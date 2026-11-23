<?php

declare(strict_types=1);

namespace DH\Auditor\Provider\Doctrine\Persistence\Command;

use DH\Auditor\Auditor;
use DH\Auditor\Provider\Doctrine\Auditing\Root\RootIndexRebuilder;
use DH\Auditor\Provider\Doctrine\Configuration;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Tests\Provider\Doctrine\Persistence\Command\RebuildRootsCommandTest;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * @see RebuildRootsCommandTest
 */
#[AsCommand(
    name: 'audit:roots:rebuild',
    description: 'Rebuilds the audit root index of existing audit entries',
)]
final class RebuildRootsCommand extends Command
{
    use LockableTrait;

    private Auditor $auditor;

    public function setAuditor(Auditor $auditor): self
    {
        $this->auditor = $auditor;

        return $this;
    }

    protected function configure(): void
    {
        $this
            ->addOption('entity', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Entities whose entries are indexed (FQCN), all by default')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count the index rows without writing anything')
            ->setHelp(<<<'HELP'
                The <info>%command.name%</info> command indexes existing audit entries under their audit roots
                (#[AuditRoot] and the "roots" entity option), for instance after adding a root to an entity or after
                migrating audit tables to schema v2.

                  <info>php %command.full_name%</info>
                  <info>php %command.full_name% --entity='App\Entity\Comment' --dry-run</info>

                The diffs are replayed: a path is followed through the audit history of the entities it goes through,
                as they were when each entry was written. Only missing rows are written and no row is ever deleted,
                so the command can be run again safely, and rows written by hand or by a "root_resolver" are kept.
                Entries in schema v1, or without a transaction id, are skipped, and a warning tells how many: migrate
                them first (audit:schema:migrate --force --convert-all). Roots of a "root_resolver" cannot
                be replayed. The command fails when an --entity is not audited, when it already runs,
                or when the diffs of an entry cannot be read (the rows of the other entries are still written).
                HELP)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        /** @var list<string> $entities */
        $entities = $input->getOption('entity');

        if (!$this->lock()) {
            $io->error('The command is already running in another process.');

            return Command::FAILURE;
        }

        /** @var DoctrineProvider $provider */
        $provider = $this->auditor->getProvider(DoctrineProvider::class);

        $configuration = $provider->getConfiguration();
        \assert($configuration instanceof Configuration);
        $unknown = array_diff($entities, array_map(strval(...), array_keys($configuration->getEntities())));
        if ([] !== $unknown) {
            $io->error(\sprintf('Unknown audited entities: %s.', implode(', ', $unknown)));
            $this->release();

            return Command::FAILURE;
        }

        $rebuilder = new RootIndexRebuilder($provider);
        $tables = $rebuilder->tables([] === $entities ? null : $entities);

        if ([] === $tables) {
            $io->warning('No audited entity has audit roots declared by path (#[AuditRoot] or the "roots" option).');
            $this->release();

            return Command::SUCCESS;
        }

        $rows = [];
        $unreadable = 0;
        $skipped = 0;
        foreach ($tables as $auditTable => $table) {
            $result = $rebuilder->rebuild($auditTable, $table, $dryRun);
            $rows[] = [$auditTable, $result['entries'], $result['rows'], $result['legacy'], $result['unreadable']];
            $unreadable += $result['unreadable'];
            $skipped += $result['legacy'];
        }

        $io->table(['Audit table', 'Entries', $dryRun ? 'Missing rows' : 'Rows written', 'Skipped (v1 or no transaction id)', 'Unreadable diffs'], $rows);
        $this->release();

        if (0 < $unreadable) {
            $io->error(\sprintf('%d entries could not be read: their diffs are not valid JSON.', $unreadable));

            return Command::FAILURE;
        }

        if (0 < $skipped) {
            // not indexed: their histories miss these entries until the tables are migrated
            $io->warning([
                \sprintf('%d entries were skipped: their diffs are in schema v1, or they have no transaction id.', $skipped),
                'Run audit:schema:migrate --force --convert-all, then this command again. Entries whose transaction_hash was dropped without conversion cannot be indexed.',
            ]);
        }

        $io->success($dryRun ? 'Dry run: nothing was written.' : 'Audit root index rebuilt.');

        return Command::SUCCESS;
    }
}

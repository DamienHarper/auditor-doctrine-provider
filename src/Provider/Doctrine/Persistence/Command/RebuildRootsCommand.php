<?php

declare(strict_types=1);

namespace DH\Auditor\Provider\Doctrine\Persistence\Command;

use DH\Auditor\Auditor;
use DH\Auditor\Provider\Doctrine\Auditing\Root\RootIndexRebuilder;
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
                Schema v1 entries are skipped (see audit:schema:migrate --convert-diffs).
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
            $output->writeln('The command is already running in another process.');

            return Command::SUCCESS;
        }

        /** @var DoctrineProvider $provider */
        $provider = $this->auditor->getProvider(DoctrineProvider::class);
        $rebuilder = new RootIndexRebuilder($provider);
        $tables = $rebuilder->tables([] === $entities ? null : $entities);

        if ([] === $tables) {
            $io->warning('No audited entity has audit roots.');
            $this->release();

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($tables as $auditTable => $table) {
            $result = $rebuilder->rebuild($auditTable, $table, $dryRun);
            $rows[] = [$auditTable, $result['entries'], $result['rows'], $result['skipped']];
        }

        $io->table(['Audit table', 'Entries', $dryRun ? 'Missing rows' : 'Rows written', 'Skipped (schema v1)'], $rows);
        $io->success($dryRun ? 'Dry run: nothing was written.' : 'Audit root index rebuilt.');
        $this->release();

        return Command::SUCCESS;
    }
}

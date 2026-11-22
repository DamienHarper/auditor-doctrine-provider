<?php

declare(strict_types=1);

namespace DH\Auditor\Provider\Doctrine\Persistence\Command;

use DH\Auditor\Auditor;
use DH\Auditor\Exception\InvalidArgumentException;
use DH\Auditor\Provider\Doctrine\Auditing\Snapshot\Snapshotter;
use DH\Auditor\Provider\Doctrine\Configuration;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Tests\Provider\Doctrine\Persistence\Command\SnapshotCommandTest;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * @see SnapshotCommandTest
 */
#[AsCommand(
    name: 'audit:snapshot',
    description: 'Records the current state of existing entities in their audit tables',
)]
final class SnapshotCommand extends Command
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
            ->addArgument('entities', InputArgument::OPTIONAL | InputArgument::IS_ARRAY, 'Audited entities to snapshot (FQCN)')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Snapshot every audited entity')
            ->addOption('batch-size', 'b', InputOption::VALUE_REQUIRED, 'Number of entities read and written per database transaction', (string) Snapshotter::DEFAULT_BATCH_SIZE)
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Also snapshot entities that already have an audit history')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count the entities to snapshot without writing anything')
            ->setHelp(<<<'HELP'
                The <info>%command.name%</info> command writes one entry per existing entity in its audit table: an
                "insert" entry flagged as a snapshot, that holds the current value of every audited field.
                Use it once auditing is enabled on a database that already holds data.

                  <info>php %command.full_name% 'App\Entity\Post' 'App\Entity\Author'</info>
                  <info>php %command.full_name% --all --batch-size=1000</info>

                Entities that already have an audit history are skipped, so the command can be run again safely;
                <info>--force</info> snapshots them anyway (a manual snapshot of the current state).
                Active Doctrine filters (e.g. soft-delete) apply: filtered rows are not snapshotted.
                HELP)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var list<string> $requested */
        $requested = $input->getArgument('entities');
        $all = (bool) $input->getOption('all');
        $force = (bool) $input->getOption('force');
        $dryRun = (bool) $input->getOption('dry-run');
        $batchSize = filter_var($input->getOption('batch-size'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if (false === $batchSize) {
            $io->error('The "--batch-size" option must be a positive integer.');

            return Command::INVALID;
        }

        if ($all === ([] !== $requested)) {
            $io->error('Give the entities to snapshot, or the "--all" option.');

            return Command::INVALID;
        }

        if (!$this->lock()) {
            $output->writeln('The command is already running in another process.');

            return Command::SUCCESS;
        }

        try {
            /** @var DoctrineProvider $provider */
            $provider = $this->auditor->getProvider(DoctrineProvider::class);
            $entities = $this->resolveEntities($provider, $all ? null : $requested);
            $snapshotter = new Snapshotter($provider);

            $rows = [];
            foreach ($entities as $entity) {
                $io->section($entity);
                $progressBar = $io->createProgressBar($snapshotter->count($entity));
                $result = $snapshotter->snapshot(
                    $entity,
                    $batchSize,
                    $force,
                    $dryRun,
                    static function (int $read) use ($progressBar): void {
                        $progressBar->advance($read);
                    },
                );
                $progressBar->finish();
                $io->newLine(2);
                $rows[] = [$entity, $result->read, $result->snapshotted, $result->skipped()];
            }
        } catch (InvalidArgumentException $invalidArgumentException) {
            $io->error($invalidArgumentException->getMessage());
            $this->release();

            return Command::FAILURE;
        }

        $io->table(['Entity', 'Read', $dryRun ? 'To snapshot' : 'Snapshotted', 'Skipped'], $rows);
        $io->success($dryRun ? 'Dry run: nothing was written.' : 'Snapshot done.');
        $this->release();

        return Command::SUCCESS;
    }

    /**
     * Returns the entities to snapshot. An entity whose parent class is also selected is left out: the parent
     * query already reads its rows, and each entity is written to the audit table of its own class.
     *
     * @param null|list<string> $requested null for every audited entity
     *
     * @return list<string>
     */
    private function resolveEntities(DoctrineProvider $provider, ?array $requested): array
    {
        /** @var Configuration $configuration */
        $configuration = $provider->getConfiguration();

        if (null === $requested) {
            $requested = array_values(array_filter(
                array_keys($configuration->getEntities()),
                static fn (string $entity): bool => $provider->isAudited($entity) && $provider->hasAuditTable($entity),
            ));
        } else {
            foreach ($requested as $entity) {
                if (!$provider->isAudited($entity)) {
                    throw new InvalidArgumentException(\sprintf('Entity "%s" is not audited (unknown entity or auditing disabled).', $entity));
                }
            }
        }

        $requested = array_values(array_unique($requested));

        return array_values(array_filter(
            $requested,
            static function (string $entity) use ($provider, $requested): bool {
                $parents = $provider->getAuditingServiceForEntity($entity)->getEntityManager()->getClassMetadata($entity)->parentClasses;

                return [] === array_intersect($parents, $requested);
            },
        ));
    }
}

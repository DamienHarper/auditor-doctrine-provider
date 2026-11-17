<?php

declare(strict_types=1);

namespace DH\Auditor\Provider\Doctrine\Persistence\Command;

use DH\Auditor\Auditor;
use DH\Auditor\Exception\InvalidArgumentException;
use DH\Auditor\Model\RetentionPolicy;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Provider\Doctrine\Persistence\Retention\RetentionPruner;
use DH\Auditor\Tests\Provider\Doctrine\Persistence\Command\CleanAuditLogsCommandTest;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * @see CleanAuditLogsCommandTest
 */
#[AsCommand(
    name: 'audit:clean',
    description: 'Cleans audit tables',
)]
final class CleanAuditLogsCommand extends Command
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
            ->addOption('no-confirm', null, InputOption::VALUE_NONE, 'No interaction mode')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Do not execute SQL queries.')
            ->addOption('dump-sql', null, InputOption::VALUE_NONE, 'Prints SQL related queries.')
            ->addOption('exclude', 'x', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Entities to exclude from cleaning')
            ->addOption('include', 'i', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Entities to include in cleaning')
            ->addOption('date', 'd', InputOption::VALUE_REQUIRED, 'Specify a custom date to clean audits until (must be expressed as an ISO 8601 date, e.g. 2023-04-24). Entities with a "max_age" retention policy use their own cutoff instead.')
            ->addArgument('keep', InputArgument::OPTIONAL, 'Audits retention period (must be expressed as an ISO 8601 date interval, e.g. P12M to keep the last 12 months or P7D to keep the last 7 days). Entities with a "max_age" retention policy use their own period instead.', 'P12M')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->lock()) {
            $output->writeln('The command is already running in another process.');

            return Command::SUCCESS;
        }

        $io = new SymfonyStyle($input, $output);

        /** @var DoctrineProvider $provider */
        $provider = $this->auditor->getProvider(DoctrineProvider::class);
        $pruner = new RetentionPruner($provider);

        $keep = $input->getArgument('keep');
        $keep = (\is_array($keep) ? $keep[0] : $keep);

        /** @var ?string $date */
        $date = $input->getOption('date');
        $until = null;

        if (null !== $date) {
            // Use custom date if provided (interpreted in the timezone audit entries are written in)
            try {
                $until = new \DateTimeImmutable($date, $pruner->getTimezone())->setTimezone($pruner->getTimezone());
            } catch (\Exception) {
                $io->error(\sprintf('Invalid date format provided: %s', $date));
            }
        } else {
            // Fall back to default retention period
            $until = $this->validateKeepArgument($keep, $pruner, $io);
        }

        if (!$until instanceof \DateTimeImmutable) {
            $this->release();

            return Command::FAILURE;
        }

        // Collect auditable classes from auditing storage managers
        $rawExcludeValues = $input->getOption('exclude') ?? [];
        $rawIncludeValues = $input->getOption('include') ?? [];
        $excludeEntities = \is_array($rawExcludeValues) ? $rawExcludeValues : [$rawExcludeValues];
        $includeEntities = \is_array($rawIncludeValues) ? $rawIncludeValues : [$rawIncludeValues];
        $entities = array_values(array_filter(
            $pruner->getAuditableEntities(),
            static fn (string $entity): bool => !\in_array($entity, $excludeEntities, true)
                && ([] === $includeEntities || \in_array($entity, $includeEntities, true)),
        ));
        $count = \count($entities);

        // Per-entity retention policies are all validated before anything is deleted.
        try {
            $policies = $pruner->getPolicies($entities);
        } catch (InvalidArgumentException $invalidArgumentException) {
            $io->error(explode("\n", $invalidArgumentException->getMessage()));
            $this->release();

            return Command::FAILURE;
        }

        if ([] !== $policies) {
            $io->writeln('Per-entity retention policies (they override the global cutoff for these entities):');
            $io->table(
                ['Entity', 'Max age', 'Max entries'],
                array_map(
                    static fn (string $entity, RetentionPolicy $policy): array => [$entity, $policy->maxAge ?? '-', $policy->maxEntries ?? '-'],
                    array_keys($policies),
                    $policies,
                ),
            );
        }

        $message = \sprintf(
            "You are about to clean audits created before <comment>%s</comment>: %d classes involved.\n Do you want to proceed?",
            $until->format(RetentionPruner::DATE_FORMAT),
            $count
        );

        $confirm = $input->getOption('no-confirm') ? true : $io->confirm($message, false);
        $dryRun = (bool) $input->getOption('dry-run');
        $dumpSQL = (bool) $input->getOption('dump-sql');

        if ($confirm) {
            $progressBar = new ProgressBar($output, $count);
            $progressBar->setBarWidth(70);
            $progressBar->setFormat("%message%\n".$progressBar->getFormatDefinition('debug'));

            $progressBar->setMessage('Starting...');
            $progressBar->start();

            $queries = [];

            foreach ($entities as $entity) {
                $statements = $pruner->pruneEntity($entity, $policies[$entity] ?? null, $until, $dryRun);
                array_push($queries, ...$statements);

                $progressBar->setMessage(\sprintf('Cleaning audit tables... (<info>%s</info>)', $entity));
                $progressBar->advance();
            }

            $progressBar->setMessage('Cleaning audit tables... (<info>done</info>)');
            $progressBar->display();

            $io->newLine();
            if ($dumpSQL) {
                $io->newLine();
                $io->writeln('SQL queries to be run:');
                foreach ($queries as $query) {
                    $io->writeln($query);
                }
            }

            $io->newLine();
            $io->success('Success.');
        } else {
            $io->success('Cancelled.');
        }

        // if not released explicitly, Symfony releases the lock
        // automatically when the execution of the command ends
        $this->release();

        return Command::SUCCESS;
    }

    private function validateKeepArgument(string $keep, RetentionPruner $pruner, SymfonyStyle $io): ?\DateTimeImmutable
    {
        try {
            $dateInterval = new \DateInterval($keep);
        } catch (\Exception) {
            $io->error(\sprintf("'keep' argument must be a valid ISO 8601 date interval, '%s' given.", $keep));

            return null;
        }

        return $pruner->now()->sub($dateInterval);
    }
}

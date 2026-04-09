<?php

declare(strict_types=1);

namespace DH\Auditor\Provider\Doctrine\Persistence\Command;

use DH\Auditor\Auditor;
use DH\Auditor\Exception\InvalidArgumentException;
use DH\Auditor\Model\Entry;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\DateRangeFilter;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\SimpleFilter;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Query;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Reader;
use DH\Auditor\Provider\Doctrine\Persistence\Schema\SchemaManager;
use DH\Auditor\Tests\Provider\Doctrine\Persistence\Command\ExportAuditLogsCommandTest;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * @see ExportAuditLogsCommandTest
 */
#[AsCommand(
    name: 'audit:export',
    description: 'Exports audit log entries to CSV, JSON, or NDJSON',
)]
final class ExportAuditLogsCommand extends Command
{
    use LockableTrait;

    private const array VALID_FORMATS = ['ndjson', 'json', 'csv'];

    private Auditor $auditor;

    public function setAuditor(Auditor $auditor): self
    {
        $this->auditor = $auditor;

        return $this;
    }

    protected function configure(): void
    {
        $this
            ->addArgument('entity', InputArgument::OPTIONAL, 'Entity FQCN to export. Omit to export all auditable entities.')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Filter by object ID.')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Export entries created on or after this date (ISO 8601, e.g. 2025-01-01).')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Export entries created on or before this date (ISO 8601, e.g. 2025-12-31).')
            ->addOption('blame-id', null, InputOption::VALUE_REQUIRED, 'Filter by blame/user ID.')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: ndjson, json, csv. NDJSON is recommended for large exports.', 'ndjson')
            ->addOption('anonymize', null, InputOption::VALUE_NONE, 'Mask blame fields (blame_id, blame_user, ip) in the output.')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Write output to this file path. Defaults to stdout.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->lock()) {
            $output->writeln('The command is already running in another process.');

            return Command::SUCCESS;
        }

        $io = new SymfonyStyle($input, $output);

        /** @var string $format */
        $format = $input->getOption('format') ?? 'ndjson';

        if (!\in_array($format, self::VALID_FORMATS, true)) {
            $io->error(\sprintf("Invalid format '%s'. Allowed formats: %s.", $format, implode(', ', self::VALID_FORMATS)));
            $this->release();

            return Command::FAILURE;
        }

        $from = $this->parseDate((string) ($input->getOption('from') ?? ''), 'from', $io);

        if (false === $from) {
            $this->release();

            return Command::FAILURE;
        }

        $to = $this->parseDate((string) ($input->getOption('to') ?? ''), 'to', $io);

        if (false === $to) {
            $this->release();

            return Command::FAILURE;
        }

        /** @var DoctrineProvider $provider */
        $provider = $this->auditor->getProvider(DoctrineProvider::class);
        $schemaManager = new SchemaManager($provider);
        $reader = new Reader($provider);

        /** @var ?string $entityArg */
        $entityArg = $input->getArgument('entity');
        $entityArg = \is_array($entityArg) ? $entityArg[0] : $entityArg;
        $entities = $this->resolveEntities($entityArg, $schemaManager, $io);

        /** @var ?string $outputPath */
        $outputPath = $input->getOption('output');
        $outputPath = \is_array($outputPath) ? $outputPath[0] : $outputPath;

        // Open file stream if an output path was given; otherwise write via OutputInterface.
        $fileStream = null;

        if (null !== $outputPath && '' !== $outputPath) {
            $fileStream = @fopen($outputPath, 'w');

            if (false === $fileStream) {
                $io->error(\sprintf("Cannot open output file: '%s'.", $outputPath));
                $this->release();

                return Command::FAILURE;
            }
        }

        $write = null !== $fileStream
            ? static function (string $data) use ($fileStream): void { fwrite($fileStream, $data); }
        : static function (string $data) use ($output): void { $output->write($data, false, OutputInterface::OUTPUT_RAW); };

        /** @var ?string $id */
        $id = $input->getOption('id');

        /** @var ?string $blameId */
        $blameId = $input->getOption('blame-id');
        $anonymize = (bool) $input->getOption('anonymize');
        $timezone = new \DateTimeZone($provider->getAuditor()->getConfiguration()->timezone);

        if ('json' === $format) {
            $write('[');
        }

        $first = true;
        $headerWritten = false;

        foreach ($entities as $entityFqcn) {
            try {
                $query = $reader->createQuery($entityFqcn, ['page_size' => null]);
            } catch (InvalidArgumentException) {
                $io->warning(\sprintf("Entity '%s' is not auditable or could not be found. Skipping.", $entityFqcn));

                continue;
            }

            if (null !== $id && '' !== $id) {
                $query->addFilter(new SimpleFilter(Query::OBJECT_ID, $id));
            }

            if (null !== $blameId && '' !== $blameId) {
                $query->addFilter(new SimpleFilter(Query::USER_ID, $blameId));
            }

            if ($from instanceof \DateTimeImmutable || $to instanceof \DateTimeImmutable) {
                $query->addFilter(new DateRangeFilter(Query::CREATED_AT, $from ?: null, $to ?: null));
            }

            foreach ($query->iterate() as $row) {
                \assert(\is_string($row['created_at']));
                $row['created_at'] = new \DateTimeImmutable($row['created_at'], $timezone);

                if ($anonymize) {
                    $row = $this->anonymizeRow($row);
                }

                $entry = Entry::fromArray($row);
                $data = $entry->toArray();

                match ($format) {
                    'ndjson' => $this->writeNdjsonRow($write, $data),
                    'json' => $this->writeJsonRow($write, $data, $first),
                    'csv' => $this->writeCsvRow($write, $data, $headerWritten),
                };
            }
        }

        if ('json' === $format) {
            $write(']');
        }

        if (null !== $fileStream) {
            fclose($fileStream);
        }

        $this->release();

        return Command::SUCCESS;
    }

    /**
     * Returns list of entity FQCNs to export (empty list if none found).
     *
     * @return list<string>
     */
    private function resolveEntities(?string $entityArg, SchemaManager $schemaManager, SymfonyStyle $io): array
    {
        if (null !== $entityArg && '' !== $entityArg) {
            return [$entityArg];
        }

        $repository = $schemaManager->collectAuditableEntities();
        $entities = [];

        foreach ($repository as $classes) {
            foreach (array_keys($classes) as $entityFqcn) {
                $entities[] = $entityFqcn;
            }
        }

        if ([] === $entities) {
            $io->warning('No auditable entities found.');

            return [];
        }

        return $entities;
    }

    /**
     * Returns false on error, null for empty string (no date), or DateTimeImmutable.
     */
    private function parseDate(string $value, string $name, SymfonyStyle $io): \DateTimeImmutable|false|null
    {
        if ('' === $value) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            $io->error(\sprintf("Invalid date for --%s: '%s'. Use ISO 8601 format (e.g. 2025-01-01).", $name, $value));

            return false;
        }
    }

    /**
     * Nullifies blame-related fields in a raw DB row before Entry::fromArray() hydration.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function anonymizeRow(array $row): array
    {
        $row['blame_id'] = null;
        $row['blame'] = null;
        // v1 legacy columns
        $row['blame_user'] = null;
        $row['blame_user_fqdn'] = null;
        $row['blame_user_firewall'] = null;
        $row['ip'] = null;

        return $row;
    }

    /**
     * @param \Closure(string): void $write
     * @param array<string, mixed>   $data
     */
    private function writeNdjsonRow(\Closure $write, array $data): void
    {
        $write(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n");
    }

    /**
     * @param \Closure(string): void $write
     * @param array<string, mixed>   $data
     */
    private function writeJsonRow(\Closure $write, array $data, bool &$first): void
    {
        if (!$first) {
            $write(',');
        }

        $write(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $first = false;
    }

    /**
     * @param \Closure(string): void $write
     * @param array<string, mixed>   $data
     */
    private function writeCsvRow(\Closure $write, array $data, bool &$headerWritten): void
    {
        if (!$headerWritten) {
            $write($this->toCsvLine(array_keys($data)));
            $headerWritten = true;
        }

        $write($this->toCsvLine($this->flattenForCsv($data)));
    }

    /**
     * Converts an array to a CSV-formatted string using fputcsv on a temp stream.
     *
     * @param list<null|bool|float|int|string> $fields
     */
    private function toCsvLine(array $fields): string
    {
        $stream = fopen('php://temp', 'r+');
        \assert(\is_resource($stream));
        fputcsv($stream, $fields);
        rewind($stream);
        $line = stream_get_contents($stream);
        fclose($stream);

        return \is_string($line) ? $line : '';
    }

    /**
     * Converts nested arrays (diffs, extra_data) to JSON strings for CSV cells.
     *
     * @param array<string, mixed> $data
     *
     * @return list<null|bool|float|int|string>
     */
    private function flattenForCsv(array $data): array
    {
        return array_values(array_map(
            static fn (mixed $value): bool|float|int|string|null => \is_array($value)
                ? json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
                : (\is_scalar($value) || null === $value ? $value : null),
            $data
        ));
    }
}

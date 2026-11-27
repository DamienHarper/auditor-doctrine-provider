---
description: Measured cost of auditing a Doctrine flush in 1.x and 2.0, with and without batch INSERT, on SQLite, MySQL and PostgreSQL, and duration of the v2 migration.
---

# Performance

> **Cost of auditing a flush, and duration of the schema v2 migration**

This page gives measured figures for the flush pipeline of the provider (audit entries built in `onFlush` and written
before the `COMMIT`), comparing the latest 1.x release with 2.0, with and without [batch INSERT](configuration.md#-batch-insert),
and the duration of `audit:schema:migrate` on a large schema v1 table. They come from one machine: use them as orders
of magnitude and to compare configurations, and measure your own workload before relying on a precise figure.

## 📊 Summary

- **2.0 with `batch_size: 0`** (the default) costs about the same as 1.2.1: from 10 % faster to 2 % slower on SQLite,
  within ±3 % on PostgreSQL from 500 entities per flush. On MySQL, and with 50 entities per flush, the differences in
  either direction stay within the dispersion of the measurements.
- **2.0 with `batch_size: 500`** writes the audit entries of a flush with multi-row `INSERT` statements. With 500 or
  1,000 entities per flush, a flush is **10 to 18 % faster** than with 1.2.1 on SQLite, **19 to 51 % faster** on
  MySQL and **44 to 67 % faster** on PostgreSQL. The more round trips cost (a remote database server, a network),
  the larger the gain.
- **Before the 2.0 release**, fewer indexes, `RETURNING id` on PostgreSQL, a lighter transaction page, deferred joins
  for deep pages on MySQL and a batched `audit:snapshot`: see [Before the 2.0 release](#-before-the-20-release-write-read-and-snapshot).
- **Migrating** a 1,000,000-row schema v1 audit table with `audit:schema:migrate --force --convert-all` took
  **5 minutes on MySQL** and **3 minutes on PostgreSQL**.
  See [Schema v2 migration](#-schema-v2-migration).

## 🚀 Before the 2.0 release: write, read and snapshot

A last series of optimizations landed before the 2.0 release, each measured with the PHPBench suites of
`benchmarks/` (`AuditBench`, `ReadBench`, `SnapshotBench`), `main` before the changes against the branch, back to back
on the same machine:

| Change | Measured effect |
|---|---|
| 3 secondary indexes fewer on audit tables (`object_id`, `blame_id` and `discriminator` alone) | Flushes 20 to 28 % faster on PostgreSQL; indexes 30 % smaller on MySQL (raw `INSERT`s 18 % faster on a 300,000-row table) |
| `INSERT … RETURNING id` on PostgreSQL with `batch_size: 0` (no `SELECT LASTVAL()` per entry) | Included in the PostgreSQL figures above (raw `INSERT`: 152-178 → 82-87 µs per row) |
| `Reader::getAuditsByTransactionId()` introspects each audit table only, not the whole database | Transaction page 89 to 99 % faster (200 unrelated tables) |
| Deep pages of an unfiltered query read with a deferred join on MySQL and MariaDB | Page 500 of 1,000: 55 → 4 ms on MySQL |
| `audit:snapshot` writes multi-row `INSERT`s and loads the association targets of a batch together | Snapshot 40 % faster on SQLite, 73 % on MySQL, 79 % on PostgreSQL |
| `extra_data_provider` called once per flush; driver, storage mapper and association metadata resolved once | Within the dispersion of the flush figures |

### Method

- **Machine**: cloud container (Linux), PHP 8.5.13 CLI without Xdebug, MySQL 8.4 and PostgreSQL 17 (official Docker
  images, default configuration, reached on `127.0.0.1`), SQLite in memory.
- **Flush** (`AuditBench`): `BENCH_N=500` entities per flush, `BENCH_SEED_ROWS=20000` rows already in the audit
  tables, `batch_size: 0`; 5 iterations (10 for the SQLite re-run reported below).
- **Read** (`ReadBench`): `BENCH_READ_ROWS=50000` rows in `post_audit`, 200 unrelated tables in the database; 5
  iterations of 5 revolutions after a warm-up.
- **Snapshot** (`SnapshotBench`): 2,000 posts of 200 authors, default batch size (500); 5 iterations.
- Reported: the mode in milliseconds, its rstdev, and the change. Results within ±10 % with an rstdev of the same order
  are noise.

### Results

#### SQLite (in memory)

| Scenario | Before | After | Change |
|---|---:|---:|---:|
| `insert` | 26.8 (±13 %) | 24.8 (±5 %) | -8 % |
| `update` | 25.8 (±5 %) | 24.1 (±7 %) | -6 % |
| `remove` | 21.7 (±8 %) | 18.4 (±5 %) | -15 % |
| `associate` | 37.3 (±23 %) | 36.1 (±6 %) | -3 % |
| `dissociate` | 36.9 (±5 %) | 35.1 (±8 %) | -5 % |
| `mixed` | 22.2 (±11 %) | 22 (±6 %) | -1 % |
| `history` | 0.128 (±22 %) | 0.115 (±7 %) | -10 % |
| `list first page` | 0.291 (±13 %) | 0.21 (±9 %) | -28 % |
| `list deep page` | 0.817 (±7 %) | 0.856 (±5 %) | +5 % |
| `count` | 1.47 (±10 %) | 1.43 (±4 %) | -2 % |
| `transaction` | 82.6 (±2 %) | 0.947 (±8 %) | -99 % |
| `snapshot` | 169 (±14 %) | 102 (±2 %) | -40 % |

The first SQLite flush run gave +6 to +37 % with rstdev up to 23 %; run again with 10 iterations, back to back, it
gave the figures above. The flush changes do not touch what SQLite measures here (in-memory, no round trip).

#### MySQL 8.4

| Scenario | Before | After | Change |
|---|---:|---:|---:|
| `insert` | 218 (±16 %) | 225 (±6 %) | +3 % |
| `update` | 317 (±9 %) | 301 (±10 %) | -5 % |
| `remove` | 215 (±6 %) | 216 (±7 %) | +1 % |
| `associate` | 423 (±7 %) | 385 (±8 %) | -9 % |
| `dissociate` | 396 (±11 %) | 345 (±9 %) | -13 % |
| `mixed` | 268 (±12 %) | 176 (±10 %) | -34 % |
| `history` | 0.521 (±20 %) | 0.554 (±33 %) | +6 % |
| `list first page` | 30.2 (±16 %) | 30.8 (±18 %) | +2 % |
| `list deep page` | 55 (±12 %) | 3.98 (±9 %) | -93 % |
| `count` | 4.25 (±24 %) | 4.48 (±13 %) | +5 % |
| `transaction` | 80.9 (±4 %) | 6.08 (±8 %) | -92 % |
| `snapshot` | 849 (±3 %) | 233 (±4 %) | -73 % |

`list first page` takes 30 ms on both sides: on a table seeded right before the measure, MySQL sorts the rows instead
of reading the `created_at` index backwards (its statistics are not up to date yet).

#### PostgreSQL 17

| Scenario | Before | After | Change |
|---|---:|---:|---:|
| `insert` | 308 (±10 %) | 222 (±15 %) | -28 % |
| `update` | 245 (±9 %) | 195 (±9 %) | -21 % |
| `remove` | 214 (±7 %) | 170 (±11 %) | -21 % |
| `associate` | 364 (±7 %) | 275 (±4 %) | -25 % |
| `dissociate` | 337 (±6 %) | 271 (±8 %) | -20 % |
| `mixed` | 234 (±6 %) | 226 (±5 %) | -3 % |
| `history` | 0.434 (±19 %) | 0.547 (±18 %) | +26 % |
| `list first page` | 0.746 (±15 %) | 0.74 (±12 %) | -1 % |
| `list deep page` | 26.5 (±43 %) | 24.3 (±6 %) | -8 % |
| `count` | 3.92 (±7 %) | 3.93 (±11 %) | +0 % |
| `transaction` | 95.5 (±3 %) | 10.5 (±25 %) | -89 % |
| `snapshot` | 886 (±6 %) | 189 (±3 %) | -79 % |

`history` read again with 10 iterations of 20 revolutions: 0.421 ms (±17 %) before, 0.411 ms (±23 %) after (−2 %).

## 🧪 Flush benchmarks

### Method

The benchmark is `benchmarks/AuditBench.php` ([PHPBench](https://phpbench.readthedocs.io/)). Each scenario changes
`N` entities of the test fixtures (`Author`, `Post`, `Tag`), all audited, and measures the single `flush()` that
writes them — the Doctrine work and the auditing (changesets, diffs, audit rows). The schema is rebuilt and the data
seeded before each iteration, outside the measured time.

| Scenario     | Measured flush                                                                    | Audit rows |
|--------------|-----------------------------------------------------------------------------------|-----------:|
| `insert`     | insert `N` authors                                                                | `N`        |
| `update`     | update 3 fields of `N` posts                                                      | `N`        |
| `remove`     | remove `N` authors                                                                | `N`        |
| `associate`  | add a tag to `N` posts (many-to-many)                                             | `2N`       |
| `dissociate` | remove that tag from `N` posts                                                    | `2N`       |
| `mixed`      | insert `N/2` authors, update `N/4` of the existing ones (2 fields), remove `N/4`  | `N`        |

Three configurations were measured back to back, for each database and each `N`:

| Configuration                | Code                                                                       |
|------------------------------|----------------------------------------------------------------------------|
| 1.2.1                        | tag `1.2.1` (latest 1.x release), schema v1                                |
| 2.0-dev, `batch_size: 0`     | `main` at `169793e` (batch INSERT), schema v2, one `INSERT` per entry¹ |
| 2.0-dev, `batch_size: 500`   | the same code with `batch_size: 500`                                       |

¹ The PostgreSQL `N` = 50 triplet was run again on `main` at `ae51e10`, which only changes `audit:schema:migrate`
(see [Reading the results](#reading-the-results)). The first MySQL `N` = 1,000 triplet was discarded and run again:
a dependency installation was running on the machine at the same time.

1.2.1 has no benchmark: `benchmarks/AuditBench.php` and `phpbench.json` of `main` were copied into a checkout of the
`1.2.1` tag, with one change — the `batch_size` option was removed from the provider configuration, since 1.2.1 does
not have it (its configuration rejects unknown options). The scenarios, fixtures and data are the same.

Settings: `N` = 50, 500 and 1,000; 10 iterations of 1 revolution, no warm-up; the reported value is the **mode** of
the 10 iterations (PHPBench's kernel density estimate), with its relative standard deviation (rstdev).

### Environment

| Item       | Value                                                                                         |
|------------|-----------------------------------------------------------------------------------------------|
| Machine    | Apple M3 (8 cores), 16 GiB RAM, macOS 27.3                                                    |
| PHP        | 8.5.12 (CLI, NTS), Xdebug not loaded and `XDEBUG_MODE=off`, OPcache disabled for the CLI      |
| Libraries  | doctrine/dbal 4.6.0, doctrine/orm 3.8.0, phpbench 1.7.0; damienharper/auditor 4.3.1 (1.2.1) and `master` at `aca4003` (2.0-dev) |
| SQLite     | file database on the local SSD (`pdo-sqlite`)                                                 |
| MySQL      | 8.0.46 (`mysql:8.0` image, default configuration), `pdo-mysql`                                |
| PostgreSQL | 15.20 (`postgres:15` image, default configuration), `pdo-pgsql`                               |
| Containers | OrbStack (8 CPUs, 9.8 GiB), data on Docker volumes (on disk, no tmpfs), reached on `127.0.0.1` |

### Reproduce

Start the databases (any MySQL 8 / PostgreSQL 15 server will do; the benchmark empties the database it is given):

```bash
docker run -d --name bench-mysql -e MYSQL_ROOT_PASSWORD=password -e MYSQL_DATABASE=bench -e MYSQL_USER=auditor -e MYSQL_PASSWORD=password -p 33377:3306 mysql:8.0
```

```bash
docker run -d --name bench-pgsql -e POSTGRES_PASSWORD=password -e POSTGRES_DB=bench -p 54377:5432 postgres:15
```

Run a configuration (here 2.0 with `batch_size: 500`, 1,000 entities, PostgreSQL):

```bash
BENCH_N=1000 BENCH_BATCH_SIZE=500 BENCH_DATABASE_URL='pdo-pgsql://postgres:password@127.0.0.1:54377/bench?serverVersion=15' XDEBUG_MODE=off vendor/bin/phpbench run benchmarks/AuditBench.php --iterations=10 --revs=1 --report=aggregate
```

- `BENCH_DATABASE_URL` takes a DBAL URL. Use the `pdo-mysql://`, `pdo-pgsql://` and `pdo-sqlite:///` schemes: with
  `pgsql://`, DBAL picks the native `pgsql` driver. For SQLite on disk, give an absolute path after the third slash
  (`pdo-sqlite:////path/to/bench.sqlite`); without `BENCH_DATABASE_URL`, the benchmark uses in-memory SQLite.
- `BENCH_BATCH_SIZE` defaults to `0`; it is ignored by the 1.2.1 copy of the benchmark.
- Run the configurations you compare back to back, with the same `N`, and nothing else running on the machine.

### Results

Mode in milliseconds, rstdev in parentheses, and change against 1.2.1 (negative = faster).

#### SQLite (file)

**N = 50** — mode in ms (relative standard deviation), change against 1.2.1

| Scenario | 1.2.1 | 2.0-dev, `batch_size: 0` | 2.0-dev, `batch_size: 500` |
|---|---:|---:|---:|
| `insert` | 4.92 (±43 %) | 5.03 (±27 %) — +2 % | 4.91 (±4 %) — -0 % |
| `update` | 2.35 (±3 %) | 2.21 (±4 %) — -6 % | 2.06 (±1 %) — -13 % |
| `remove` | 3.69 (±14 %) | 3.43 (±14 %) — -7 % | 3.31 (±20 %) — -10 % |
| `associate` | 3.94 (±9 %) | 3.65 (±10 %) — -7 % | 3.31 (±2 %) — -16 % |
| `dissociate` | 3.43 (±4 %) | 3.16 (±2 %) — -8 % | 2.80 (±8 %) — -18 % |
| `mixed` | 3.77 (±1 %) | 3.80 (±7 %) — +1 % | 3.61 (±3 %) — -4 % |

**N = 500** — mode in ms (relative standard deviation), change against 1.2.1

| Scenario | 1.2.1 | 2.0-dev, `batch_size: 0` | 2.0-dev, `batch_size: 500` |
|---|---:|---:|---:|
| `insert` | 18.9 (±6 %) | 18.4 (±2 %) — -3 % | 17.1 (±2 %) — -10 % |
| `update` | 19.8 (±2 %) | 18.9 (±2 %) — -5 % | 17.7 (±2 %) — -10 % |
| `remove` | 15.0 (±5 %) | 14.7 (±4 %) — -1 % | 13.5 (±3 %) — -10 % |
| `associate` | 30.3 (±1 %) | 27.4 (±4 %) — -9 % | 24.8 (±5 %) — -18 % |
| `dissociate` | 30.3 (±1 %) | 27.3 (±1 %) — -10 % | 24.7 (±2 %) — -18 % |
| `mixed` | 17.5 (±1 %) | 16.5 (±2 %) — -6 % | 15.3 (±5 %) — -12 % |

**N = 1000** — mode in ms (relative standard deviation), change against 1.2.1

| Scenario | 1.2.1 | 2.0-dev, `batch_size: 0` | 2.0-dev, `batch_size: 500` |
|---|---:|---:|---:|
| `insert` | 35.3 (±4 %) | 33.3 (±4 %) — -6 % | 31.1 (±3 %) — -12 % |
| `update` | 39.6 (±1 %) | 37.9 (±3 %) — -4 % | 35.5 (±1 %) — -10 % |
| `remove` | 30.4 (±3 %) | 29.5 (±3 %) — -3 % | 27.4 (±1 %) — -10 % |
| `associate` | 61.1 (±2 %) | 55.3 (±2 %) — -10 % | 49.9 (±3 %) — -18 % |
| `dissociate` | 60.9 (±1 %) | 55.7 (±5 %) — -8 % | 50.5 (±3 %) — -17 % |
| `mixed` | 32.9 (±1 %) | 31.2 (±3 %) — -5 % | 28.2 (±1 %) — -14 % |

#### MySQL 8.0

**N = 50** — mode in ms (relative standard deviation), change against 1.2.1

| Scenario | 1.2.1 | 2.0-dev, `batch_size: 0` | 2.0-dev, `batch_size: 500` |
|---|---:|---:|---:|
| `insert` | 26.5 (±6 %) | 23.9 (±14 %) — -10 % | 23.4 (±35 %) — -12 % |
| `update` | 14.7 (±11 %) | 13.3 (±17 %) — -9 % | 10.5 (±30 %) — -28 % |
| `remove` | 15.9 (±12 %) | 17.1 (±31 %) — +7 % | 12.2 (±25 %) — -24 % |
| `associate` | 24.7 (±8 %) | 24.2 (±15 %) — -2 % | 15.5 (±14 %) — -37 % |
| `dissociate` | 19.8 (±37 %) | 24.2 (±19 %) — +22 % | 15.2 (±28 %) — -23 % |
| `mixed` | 18.5 (±16 %) | 21.6 (±30 %) — +17 % | 16.9 (±32 %) — -9 % |

**N = 500** — mode in ms (relative standard deviation), change against 1.2.1

| Scenario | 1.2.1 | 2.0-dev, `batch_size: 0` | 2.0-dev, `batch_size: 500` |
|---|---:|---:|---:|
| `insert` | 124.8 (±8 %) | 127.8 (±12 %) — +2 % | 91.0 (±12 %) — -27 % |
| `update` | 117.5 (±6 %) | 135.7 (±14 %) — +15 % | 93.8 (±9 %) — -20 % |
| `remove` | 96.7 (±8 %) | 110.5 (±22 %) — +14 % | 78.7 (±9 %) — -19 % |
| `associate` | 185.7 (±8 %) | 181.7 (±10 %) — -2 % | 118.5 (±5 %) — -36 % |
| `dissociate` | 186.5 (±9 %) | 178.9 (±14 %) — -4 % | 115.4 (±10 %) — -38 % |
| `mixed` | 107.0 (±5 %) | 122.2 (±51 %) — +14 % | 80.6 (±8 %) — -25 % |

**N = 1000** — mode in ms (relative standard deviation), change against 1.2.1

| Scenario | 1.2.1 | 2.0-dev, `batch_size: 0` | 2.0-dev, `batch_size: 500` |
|---|---:|---:|---:|
| `insert` | 225.4 (±15 %) | 265.6 (±20 %) — +18 % | 154.7 (±12 %) — -31 % |
| `update` | 219.5 (±5 %) | 202.9 (±8 %) — -8 % | 130.0 (±17 %) — -41 % |
| `remove` | 198.7 (±13 %) | 181.1 (±3 %) — -9 % | 116.2 (±6 %) — -42 % |
| `associate` | 362.4 (±4 %) | 321.0 (±10 %) — -11 % | 179.9 (±5 %) — -50 % |
| `dissociate` | 299.2 (±6 %) | 274.8 (±5 %) — -8 % | 147.9 (±3 %) — -51 % |
| `mixed` | 209.3 (±4 %) | 210.5 (±4 %) — +1 % | 153.3 (±31 %) — -27 % |

#### PostgreSQL 15

**N = 50** — mode in ms (relative standard deviation), change against 1.2.1

| Scenario | 1.2.1 | 2.0-dev, `batch_size: 0` | 2.0-dev, `batch_size: 500` |
|---|---:|---:|---:|
| `insert` | 43.3 (±12 %) | 38.1 (±16 %) — -12 % | 29.8 (±25 %) — -31 % |
| `update` | 15.9 (±13 %) | 16.3 (±23 %) — +2 % | 11.0 (±16 %) — -31 % |
| `remove` | 19.7 (±10 %) | 20.6 (±12 %) — +4 % | 16.0 (±13 %) — -19 % |
| `associate` | 25.7 (±12 %) | 25.7 (±14 %) — +0 % | 11.7 (±22 %) — -55 % |
| `dissociate` | 21.7 (±6 %) | 21.2 (±8 %) — -2 % | 9.83 (±19 %) — -55 % |
| `mixed` | 24.4 (±12 %) | 26.4 (±18 %) — +8 % | 20.8 (±16 %) — -15 % |

**N = 500** — mode in ms (relative standard deviation), change against 1.2.1

| Scenario | 1.2.1 | 2.0-dev, `batch_size: 0` | 2.0-dev, `batch_size: 500` |
|---|---:|---:|---:|
| `insert` | 167.5 (±4 %) | 165.1 (±7 %) — -1 % | 93.8 (±5 %) — -44 % |
| `update` | 137.4 (±1 %) | 138.1 (±2 %) — +0 % | 65.1 (±2 %) — -53 % |
| `remove` | 132.3 (±2 %) | 133.3 (±2 %) — +1 % | 61.0 (±2 %) — -54 % |
| `associate` | 224.7 (±2 %) | 219.4 (±2 %) — -2 % | 76.6 (±5 %) — -66 % |
| `dissociate` | 224.7 (±1 %) | 219.1 (±1 %) — -3 % | 74.7 (±2 %) — -67 % |
| `mixed` | 148.0 (±2 %) | 150.5 (±2 %) — +2 % | 77.0 (±3 %) — -48 % |

**N = 1000** — mode in ms (relative standard deviation), change against 1.2.1

| Scenario | 1.2.1 | 2.0-dev, `batch_size: 0` | 2.0-dev, `batch_size: 500` |
|---|---:|---:|---:|
| `insert` | 328.4 (±2 %) | 327.8 (±2 %) — -0 % | 180.3 (±7 %) — -45 % |
| `update` | 279.1 (±2 %) | 276.5 (±7 %) — -1 % | 133.1 (±2 %) — -52 % |
| `remove` | 268.7 (±2 %) | 269.4 (±4 %) — +0 % | 124.6 (±2 %) — -54 % |
| `associate` | 452.9 (±2 %) | 442.0 (±2 %) — -2 % | 155.9 (±3 %) — -66 % |
| `dissociate` | 445.6 (±1 %) | 440.8 (±2 %) — -1 % | 151.9 (±8 %) — -66 % |
| `mixed` | 298.4 (±1 %) | 291.4 (±3 %) — -2 % | 150.2 (±2 %) — -50 % |

### Reading the results

- **Dispersion.** On SQLite and PostgreSQL, most rstdev values stay below 5 %: differences above 5 % are
  meaningful. MySQL (through the container's virtualized disk) is much noisier, with rstdev up to 51 %: there, only
  the large gains of `batch_size: 500` are significant, and the ±20 % differences between 1.2.1 and
  `batch_size: 0` are not. At `N` = 50, a flush lasts a few dozen milliseconds and the rstdev reaches 10 to 25 % on
  PostgreSQL too: the first run of the PostgreSQL `N` = 50 triplet gave the `insert` scenario 27.8 ms with 1.2.1 and
  47.2 ms with `batch_size: 0` (+70 %); run again, the three configurations back to back, it gave 43.3 ms and
  38.1 ms (−12 %). The second run is the one reported above: at `N` = 50, read only the large differences.
- **`batch_size: 0`.** Each audit entry is written with its own `INSERT`, as in 1.2.1: the schema v2 changes (JSON
  `blame`, ULID `transaction_id`, diffs envelope) do not make a flush measurably slower.
- **`batch_size: 500`.** The gain grows with `N` and with the cost of a round trip: modest on SQLite (no network,
  10 to 18 %), large on PostgreSQL (half of the time or less from `N` = 500). `associate` and `dissociate`, which write
  two audit rows per changed entity, gain the most. With `N` = 50, a flush writes 50 to 100 rows, a single multi-row
  statement: the gain is already visible but within a noisier range.

## 🗄️ Schema v2 migration

The migration of a schema v1 audit table (see [Upgrading to 2.0](../../upgrade/v2.md)) was timed on a table of
**1,000,000 rows** written by 1.2.1, with `audit:schema:migrate --force --convert-all` (2.0-dev, `main` at
`e081182`), on the machine and database servers described above.

### Method

- **Data**: `author_audit`, generated with 1.2.1 (not measured) by cycles of three flushes, each in its own
  transaction: insert 20 authors, update them (2 fields), remove 10 of them. That is 50 audit rows and 3 transactions
  per cycle, i.e. **60,000 transactions** for 1,000,000 rows (547 MiB on MySQL, 447 MiB on PostgreSQL).
- **Run**: one database after the other, nothing else running; the command output is timestamped to get the duration
  of each step.

### Results

| Step                                                  | MySQL 8.0         | PostgreSQL 15     |
|-------------------------------------------------------|------------------:|------------------:|
| Additive DDL (new columns, `schema_version`, `blame`) | 50 s              | 43 s              |
| `transaction_hash` → `transaction_id` (60,000 ULIDs)  | 100 s             | 29 s              |
| Diffs conversion (1,000,000 rows)                     | 139 s             | 118 s             |
| Legacy columns dropped                                | < 1 s             | < 1 s             |
| **Total**                                             | **4 min 59 s**    | **3 min 13 s**    |
| Table size after `OPTIMIZE TABLE` / `VACUUM FULL`     | 690 MiB (+26 %)   | 614 MiB (+37 %)   |

After the migration, both tables had 60,000 distinct `transaction_id` values (one per legacy transaction, the
grouping is kept), no row without `transaction_id`, `blame` or `source` in its diffs, and every row at
`schema_version` 2.

- **What takes the time**: every row is read, its diffs decoded, rewritten in the v2 envelope and updated. Both
  conversions work in batches of 500 rows (or transactions), each written in one database transaction, and read the
  rows through the primary key or the `transaction_hash` index: their duration grows linearly with the size of the
  audit tables.
- **Disk space**: the rows are rewritten in place, so the table grows during the migration (878 MiB on MySQL and up
  to 1,783 MiB on PostgreSQL right after it, dead rows included) until InnoDB or autovacuum reuses the space, or
  until an `OPTIMIZE TABLE` / `VACUUM FULL` reclaims it. On PostgreSQL, the table reached up to 4 times its initial
  size: check the free disk space before migrating.
- **Your own estimate**: `--limit=N` converts only `N` transactions or rows and keeps the legacy columns, so that a
  later run resumes where it stopped. Time a run on a sample and extrapolate linearly; test on a copy of your
  production data, with a backup, before the real migration.

### Reproduce

The scripts are not part of the repository; the steps are:

1. With a checkout of the `1.2.1` tag, create the audit schema and write the v1 audit rows (any audited entity and
   workload will do; the larger the table, the more representative the figures).
2. With 2.0, register the same entities and run the command, timing it:

```bash
time bin/console audit:schema:migrate --force --convert-all
```

## Next Steps

- ⚙️ [Configuration](configuration.md) — `batch_size` and the other options
- 🚀 [Upgrading to 2.0](../../upgrade/v2.md) — migrating the audit tables

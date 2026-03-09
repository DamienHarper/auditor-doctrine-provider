# auditor-doctrine-provider [![Tweet](https://img.shields.io/twitter/url/http/shields.io.svg?style=social)](https://twitter.com/intent/tweet?text=auditor-doctrine-provider,%20Doctrine%20ORM%20audit%20logs%20made%20easy.&url=https://github.com/DamienHarper/auditor-doctrine-provider&hashtags=auditor)

[![Latest Stable Version](https://poser.pugx.org/damienharper/auditor-doctrine-provider/v/stable)](https://packagist.org/packages/damienharper/auditor-doctrine-provider)
[![Latest Unstable Version](https://poser.pugx.org/damienharper/auditor-doctrine-provider/v/unstable)](https://packagist.org/packages/damienharper/auditor-doctrine-provider)
[![auditor-doctrine-provider 1.x CI](https://github.com/DamienHarper/auditor-doctrine-provider/actions/workflows/ci-1.x.yml/badge.svg)](https://github.com/DamienHarper/auditor-doctrine-provider/actions/workflows/ci-1.x.yml)
[![codecov](https://codecov.io/gh/DamienHarper/auditor-doctrine-provider/branch/main/graph/badge.svg)](https://app.codecov.io/gh/DamienHarper/auditor-doctrine-provider/branch/main)
[![License](https://poser.pugx.org/damienharper/auditor-doctrine-provider/license)](https://packagist.org/packages/damienharper/auditor-doctrine-provider)
[![Total Downloads](https://poser.pugx.org/damienharper/auditor-doctrine-provider/downloads)](https://packagist.org/packages/damienharper/auditor-doctrine-provider)
[![Monthly Downloads](https://poser.pugx.org/damienharper/auditor-doctrine-provider/d/monthly)](https://packagist.org/packages/damienharper/auditor-doctrine-provider)

The purpose of `auditor-doctrine-provider` is to bring the auditing features of [`auditor`](https://github.com/DamienHarper/auditor) to applications using [Doctrine ORM](https://www.doctrine-project.org/projects/orm.html).


## Architecture
This library implements the two-service architecture defined by the `auditor` core:
- **Auditing services** responsible for collecting audit events from Doctrine's `onFlush` event
- **Storage services** responsible for persisting audit entries to the database

Both services are provided by the `DoctrineProvider`.


## DoctrineProvider
`DoctrineProvider` offers both auditing and storage services for **Doctrine ORM** entities.
It creates audit logs for all Doctrine entity lifecycle changes:

- inserts and updates including their diffs and field-level changes.
- many-to-many relationship changes, association and dissociation actions.
- if available, the user responsible for these changes and their IP address are recorded.
- audit entries are inserted within the same database transaction as the originating flush.

`DoctrineProvider` supports the following RDBMS:
* MySQL
* MariaDB
* PostgreSQL
* SQLite

`DoctrineProvider` should work with **any other** database supported by Doctrine DBAL.
Though, we can only really support the ones we can test with [GitHub Actions](https://github.com/features/actions).

**NOTE:** Bulk operations performed via DQL (`$em->createQuery('UPDATE ...')`) or raw DBAL queries bypass
Doctrine's UnitOfWork and are therefore **not tracked**.


## Official Documentation
`auditor-doctrine-provider` documentation can be found locally in the [docs/](docs/) folder.

- [Introduction](docs/index.md)
- [Installation](docs/getting-started/installation.md)
- [Quick Start](docs/getting-started/quick-start.md)
- [DoctrineProvider](docs/providers/doctrine/index.md)
- [Configuration](docs/providers/doctrine/configuration.md)
- [Attributes](docs/providers/doctrine/attributes.md)
- [Services](docs/providers/doctrine/services.md)
- [Schema Management](docs/providers/doctrine/schema.md)
- [Multi-Database](docs/providers/doctrine/multi-database.md)
- [Querying Audits](docs/querying/index.md)
- [Extra Data](docs/extra-data.md)


## Version Information
| Version | Status                      | Requirements                                                                    |
|:--------|:----------------------------|:--------------------------------------------------------------------------------|
| 1.x     | Active development :rocket: | PHP >= 8.4, Doctrine DBAL >= 4.0, Doctrine ORM >= 3.2, auditor >= 4.0          |

Changelog is available in [CHANGELOG.md](CHANGELOG.md).


## Contributing
`auditor-doctrine-provider` is an open source project. Contributions made by the community are welcome.
Send us your ideas, code reviews, pull requests and feature requests to help us improve this project.

Do not forget to provide unit tests when contributing to this project.
See [docs/contributing.md](docs/contributing.md) for setup and guidelines.


## Credits
- Thanks to [all contributors](https://github.com/DamienHarper/auditor-doctrine-provider/graphs/contributors)
- Built on top of the [`auditor`](https://github.com/DamienHarper/auditor) core library by [Damien Harper](https://github.com/DamienHarper)
- Special thanks to [JetBrains](https://www.jetbrains.com/?from=auditor) for their *Licenses for Open Source Development*

## License
`auditor-doctrine-provider` is free to use and is licensed under the [MIT license](http://www.opensource.org/licenses/mit-license.php)

# auditor-doctrine-provider [![Tweet](https://img.shields.io/twitter/url/http/shields.io.svg?style=social)](https://twitter.com/intent/tweet?text=auditor-doctrine-provider,%20Doctrine%20ORM%20audit%20logs%20made%20easy.&url=https://github.com/DamienHarper/auditor-doctrine-provider&hashtags=auditor)

[![Latest Stable Version](https://poser.pugx.org/damienharper/auditor-doctrine-provider/v/stable)](https://packagist.org/packages/damienharper/auditor-doctrine-provider)
[![Latest Unstable Version](https://poser.pugx.org/damienharper/auditor-doctrine-provider/v/unstable)](https://packagist.org/packages/damienharper/auditor-doctrine-provider)
[![auditor-doctrine-provider 2.x CI](https://github.com/DamienHarper/auditor-doctrine-provider/actions/workflows/ci-2.x.yml/badge.svg)](https://github.com/DamienHarper/auditor-doctrine-provider/actions/workflows/ci-2.x.yml)
[![codecov](https://codecov.io/gh/DamienHarper/auditor-doctrine-provider/branch/main/graph/badge.svg)](https://app.codecov.io/gh/DamienHarper/auditor-doctrine-provider/branch/main)
[![License](https://poser.pugx.org/damienharper/auditor-doctrine-provider/license)](https://packagist.org/packages/damienharper/auditor-doctrine-provider)
[![Total Downloads](https://poser.pugx.org/damienharper/auditor-doctrine-provider/downloads)](https://packagist.org/packages/damienharper/auditor-doctrine-provider)
[![Monthly Downloads](https://poser.pugx.org/damienharper/auditor-doctrine-provider/d/monthly)](https://packagist.org/packages/damienharper/auditor-doctrine-provider)

`auditor-doctrine-provider` brings the auditing features of [`auditor`](https://github.com/DamienHarper/auditor) to applications using [Doctrine ORM](https://www.doctrine-project.org/projects/orm.html).

It records every insert, update, removal and many-to-many change of the audited entities in audit
tables, within the same database transaction, and provides a reader to query them. It supports
MySQL, MariaDB, PostgreSQL and SQLite.

- **Symfony application?** Install [`auditor-bundle`](https://github.com/DamienHarper/auditor-bundle) instead:
  it requires this package and wires everything (configuration, services, viewer).
- **Coming from auditor 4.x?** The built-in `DH\Auditor\Provider\Doctrine` namespace moved to this package,
  unchanged: see the [upgrade guides](https://damienharper.github.io/auditor-docs/auditor-doctrine-provider/upgrade).


## Using an AI coding agent?
This package ships an [Agent Skill](https://agentskills.io) in [`skills/auditor-doctrine-provider/`](skills/auditor-doctrine-provider/SKILL.md)
(`vendor/damienharper/auditor-doctrine-provider/skills/` once installed) describing the provider. The copy in `vendor/` matches your installed version; the marketplace serves the current major.
With Claude Code:
```
/plugin marketplace add DamienHarper/auditor
/plugin install auditor-doctrine-provider@auditor
```
The documentation is also available as [llms.txt](https://damienharper.github.io/auditor-docs/llms.txt).


## Official Documentation
`auditor-doctrine-provider` documentation can be found [here](https://damienharper.github.io/auditor-docs/auditor-doctrine-provider) or locally in the [docs/](docs/) folder.


## Version Information
| Version | Status                      | Requirements                                                                           |
|:--------|:----------------------------|:---------------------------------------------------------------------------------------|
| 2.x     | Active development :rocket: | PHP >= 8.4, Doctrine DBAL >= 4.6, Doctrine ORM >= 3.7, auditor >= 5.0                 |
| 1.x     | Bug and security fixes      | PHP >= 8.4, Doctrine DBAL >= 4.3, Doctrine ORM >= 3.2, auditor >= 4.1                 |

Changelog is available in the [releases](https://github.com/DamienHarper/auditor-doctrine-provider/releases), and the upgrade guides in the [documentation](https://damienharper.github.io/auditor-docs/auditor-doctrine-provider/upgrade).


## Contributing
`auditor-doctrine-provider` is an open source project. Contributions made by the community are welcome.
Send us your ideas, code reviews, pull requests and feature requests to help us improve this project.

Do not forget to provide unit tests when contributing to this project.
See [docs/contributing.md](docs/contributing.md) for setup and guidelines.


## Credits
- Thanks to [all contributors](https://github.com/DamienHarper/auditor-doctrine-provider/graphs/contributors)
- Special thanks to [JetBrains](https://www.jetbrains.com/?from=auditor) for their *Licenses for Open Source Development*

## License
`auditor-doctrine-provider` is free to use and is licensed under the [MIT license](http://www.opensource.org/licenses/mit-license.php)

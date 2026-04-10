Contributing
============

Contributions are welcome. Please follow the guidelines below to keep the codebase consistent.


Setup
-----

```bash
git clone https://github.com/YOUR_GITHUB_USERNAME/phritzbox.git
cd phritzbox/app && composer install && cd ..
cp .env .env.local   # fill in Fritz!Box credentials and DATABASE_URL
```


Running Tests
-------------

```bash
php app/vendor/bin/phpunit --configuration app/phpunit.xml.dist
```

Tests use an SQLite test database (`data/database_test.sqlite`) created automatically on first run. The DAMA DoctrineTestBundle wraps each test in a transaction and rolls it back afterwards, so the database stays clean between runs.


Code Style
----------

The project uses [php-cs-fixer](https://cs.symfony.com/) with the `@Symfony` ruleset. Non-Yoda comparison style is enforced (`$var === null`, not `null === $var`).

Check:
```bash
./app/vendor/bin/php-cs-fixer fix --dry-run --diff --config app/.php-cs-fixer.dist.php
```

Auto-fix:
```bash
./app/vendor/bin/php-cs-fixer fix --config app/.php-cs-fixer.dist.php
```

All pull requests must pass the code style check before being merged.


Commit Messages
---------------

- Use the imperative mood: "Add feature" not "Added feature"
- Keep the subject line under 72 characters
- Reference issues where relevant: `Fix null dereference in AhaApi (#42)`


Pull Requests
-------------

1. Fork the repository and create a feature branch from `master`
2. Add or update tests for any changed behaviour
3. Ensure all CI checks pass (tests, code style, lint, security audit)
4. Open a pull request against `master` with a clear description of what changed and why


Reporting Issues
----------------

Please open a GitHub issue with:
- PHP version and OS
- Fritz!Box model and firmware version
- Smart home devices used
- Steps to reproduce
- Expected vs actual behaviour

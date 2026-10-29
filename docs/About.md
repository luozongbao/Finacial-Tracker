This project is to record spending andd income for personal use. it should be able to search and sum/average in time period like day, month, weeks, year.

## stack

- webserver: litespeed
- language: php
- db: mariadb
- docker is used for development, can be used for production but not depend on docker.

## Installation
1. search for config.php if exist goto index.php
2. if not install
3. install create config.php, allow user to customize database name, database user, database password, smtp server, smtp port, email user, smtp password
4. config.php also included ALLOW_REGISTRATION default true, allow user registration. if set to false don't allow user registration, both FE/BE are restricted.

## Running it

the application never sends mail by itself. a reset link or a verification mail is written to a queue table (mail_outbox) and a worker sends it. the worker is part of the installation, not an optional extra:

```
php database/outbox_worker.php --limit=20
```

run it from cron (every minute or every five minutes is enough). without it, no mail is sent and the messages wait in the queue. the queue depth is shown in the installation card in settings, so a backlog is visible without reading a log.

the worker claims each message exclusively, so two runs at the same time never send the same message twice; a failed send is retried with the same row and a growing wait.

## Security notes

the unauthenticated screens are limited. the reset screen allows 3 requests per hour for one submitted address and 20 per hour per source address; the register screen allows 10 registrations per hour per source address. the surplus is refused with 429 and a Retry-After header. the limits are constants in config.php (RESET_ADDRESS_LIMIT, RESET_SOURCE_LIMIT, REGISTER_SOURCE_LIMIT) and REQUEST_THROTTLE_ENABLED turns the whole thing off in an emergency.

the reset screen answers the same way for every address, so it cannot be used to find out which addresses have an account. the answers are byte-identical and the timing is the same. the logs carry the context of a failure and never the address that was submitted.

the web root is the repository root, so the deny block in .htaccess is load-bearing. it refuses .env, .git, database/, includes/, views/, docker/, vendor/, composer.json, composer.lock and docs/issues/ - and, since issue-023, docker-compose.yml, config.template.php, AGENTS.md and everything under docs/ except the mockup deliverable. the development stack's default credentials are therefore not published by the web server. the liteSpeed webadmin is bound to 127.0.0.1 only, and the phpMyAdmin and nginx images are pinned by digest.

every response carries the hardening headers. the set lives in one function, sendSecurityHeaders() in includes/functions.php, and both entry points call it - the application and the installer - so the installer is not the weak one. the set is x-content-type-options, x-frame-options, referrer-policy, a strict content-security-policy (script-src 'self', style-src 'self', object-src 'none', base-uri 'none', form-action 'self', frame-ancestors 'self'), a permissions-policy that switches every feature off, cross-origin-opener-policy, cross-origin-resource-policy and x-permitted-cross-domain-policies. strict-transport-security and upgrade-insecure-requests are sent only over https. there is no unsafe-inline and no nonce, because the application has no inline script - the theme travels in a cookie - and there is no x-xss-protection, because the browsers that honoured it are the ones it broke.

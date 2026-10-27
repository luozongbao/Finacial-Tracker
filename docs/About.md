This project is to record spending andd income for personall use. it should be able to search and sum in time period like day, month, weeks, year.

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

## 
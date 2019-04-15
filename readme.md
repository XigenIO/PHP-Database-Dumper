# Database Dumper
This project allows database dumps on replicated slave by first pausing replication before dumping data and then resumes replication after completion. It can then compress and upload to a configured openstack compatible storage and also configured to send notifications to Slack of any failures.

## Setup
Setup for development

### MySQL
For this application to create dump and manage replication it needs to have the certain privileges assigned to the user. It is recommended to create a separate user just for this. Use the following bash commands to setup a new user with the correct privileges remembering to change the password from `changeme`.

```bash
mysql -h 127.0.0.1 -e "DROP USER 'databasedumper'@'%';"
mysql -h 127.0.0.1 -e "CREATE USER 'databasedumper'@'%' IDENTIFIED BY 'changeme';"
mysql -h 127.0.0.1 -e "GRANT ALL ON *.* to 'databasedumper'@'%';"
mysql -h 127.0.0.1 -e "FLUSH PRIVILEGES;"
```

## Development
Start by cloning this repo and installing required dependencies via composer. This can take a few minutes as it will pull in all of the development packages.
```bash
git clone git@github.com:XigenIO/PHP-Database-Dumper.git
cd PHP-Database-Dumper

# Build the docker image
bin/docker-build
# Install PHP composer dependencies
composer install
# Run the console comand within a Docker container
bin/docker-console
```

### Running tests
You can run the configured tests via the composer scripts functionality. It will give feedback on the quality of code via  To do this run the following command:

```bash
$ composer run-script tests
```

[docker-compose]: https://github.com/docker/compose

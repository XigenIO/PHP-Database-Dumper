# Database dump and storage
This project aims to dump a database on a replication slave by first stopping the MySQL slave and then starting it again once a database dump has been completed. It will then upload the database dump to a configured openstack compatible storage and can also be configured to send notifications to Slack on any failures.

## Setup

### MySQL
For this application to create dump and manage repliction it needs to have the certain privileges assigned to the user. It is recomened to create a separate user just for this. Use the following bash commands to setup a new user with the correct privileges remembering to change the password from `changeme`.

```bash
mysql -h 127.0.0.1 -e "DROP USER 'databasedumper'@'%';"
mysql -h 127.0.0.1 -e "CREATE USER 'databasedumper'@'%' IDENTIFIED BY 'changeme';"
mysql -h 127.0.0.1 -e "GRANT ALL ON *.* to 'databasedumper'@'%';"
mysql -h 127.0.0.1 -e "FLUSH PRIVILEGES;"
```

## Development
Start by cloning this repo and installing required dependencies via composer. This can take a few minutes as it will pull in all of the development packages.
```bash
git clone git@git.xigen.co.uk:php/database-dump-storage.git database-dump-storage
cd database-dump-storage
composer install -vvv
```

### Running tests
You can run the configured tests via the composer scripts functinallity. It will give feedback on the quality of code via  To do this run the following command:

```bash
composer run-script tests
```

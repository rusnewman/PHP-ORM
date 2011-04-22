# PHP Object-Relational-Mapper

This is an abstract class for PHP that can represent various entities in a MySQL database, removing the need to manually create classes.

Your database must be designed according to the spec in the Wiki. This spec is very similar to specs used on frameworks such as Ruby on Rails.

When your database is up to spec, it's straightforward to get started with the ORM and to write your own classes to extend its own functions.

The Wiki section has extensive documentation and examples to help you get started.

## Installation
To install the ORM in your PHP project, download the source ("Downloads" button, above) and extract it into a directory ("orm" would be a good name) within your project.
Next, include the boot.php file in your PHP, wherever you want to use it:
```php
include("../orm/boot.php");
```

Now refer to the Wiki for usage instructions.

I recommend that, where possible, you keep the ORM source files outside the directory that is published by your web server. That is, if your Apache server publishes the 'htdocs' directory, keep your ORM files above this directory, safe from prying eyes.

## Submodules, etc
This project makes use of Seb Skuse's PHP Database class to perform all database operations. The Database class is included as a git submodule.
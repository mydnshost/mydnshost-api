# mydnshost-api

This repo holds the code for the api backend for mydnshost.

This is the code that does the main heavy-lifting and is exposed as a JSON API for https://github.com/ShaneMcC/mydnshost-frontend to access.

Domain/Record data is all stored in our own database and then pushed out to our DNS Server(s) via hooks.

The code can be run either with Docker or directly on a server, though for the most part production use is only tested as a docker container.

## Running

TODO...

(Generally: check out the code, `composer install`, edit config.local.php, run `php admin/init.php` to update the database.)

## Comments, Questions, Bugs, Feature Requests etc.

At some point I'll add more to this README so that this isn't just a code-dump, but for now Bugs and Feature Requests should be raised on the [issue tracker on github](https://github.com/shanemcc/mydnshost-api/issues), and I'm happy to recieve code pull requests via github.

I can be found idling on various different IRC Networks, but the best way to get in touch would be to message "Dataforce" on Quakenet, or drop me a mail (email address is in my github profile)

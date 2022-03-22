# PROTOCOL - A Command Line Tool for Highly Available Applications

PROTOCOL is a command line tool designed to help you keep a highly available application in sync.

## USE CASE

You have a PHP application like Laravel that you track in a private git repo. You've decided to use docker running on an EC2 VPS to serve your PHP application. 

  - Application hosted on a remote git repo
  - (optional) Docker image hosted on a remote docker registry
  - You have 1 or more worker nodes serving the application behind a load balancer

Your development is done elsewhere and you want your VPS node(s) to stay up to date with your latest git repo changes and/or your docker image changes.

  - Development is done locally
  - When you push changes to your git remote master branch, you want those changes to go out to all your nodes without fail.

Most continuous delivery pipelines are just too complex. They're cumbersome to setup initially and even more complex when you introduce multiple (and always changing) auto scaling worker nodes.

I wanted something that was quick to setup, whether I was running a single node or 100. I needed something that could update new auto scaled worker nodes as soon as they came online, and something that kept the worker nodes constantly in sync.

My solution was to create a master/slave continuous deployment system. I have always lived by the idea that the MASTER branch should always be production ready. All of our work should be done on feature branches and only merged into master after all tests have passed and manual QA has been done. Therefore having a tool that builds worker nodes and keeps them in sync with the master repo was the ideal solution.

Once installed, any commits made to the master repo will immediately be replicated to the slave node, thanks to PROTOCOL.

## System Requirements

- git
- php 7.4
- composer (optional)
- docker
- docker-compose

## Installation

```
# git clone https://github.com/merchantprotocol/protocol.git /opt/protocol
# chmod +x /opt/protocol/protocol
```

### Generate a key to be used with your remote git account

This command generates the key using default params, sets the permissions, adds it to be used in every ssh command and then returns the public key to be added to your remote git account.

```
# /opt/protocol/protocol key:generate
```

### Setting up a node for the first time

Create your own config/config.php file from the provided config/config.sample.php. This is where your remote and local repositories will be declared.

Then run the installation command. This does not install protocol, but installs your git repo and builds your docker container.

```
# /opt/protocol/protocol install
```

## bin/protocol list

```
Protocol 0.1.0

Usage:
  command [options] [arguments]

Options:
  -h, --help            Display help for the given command. When no command is given display help for the list command
  -q, --quiet           Do not output any message
  -V, --version         Display this application version
      --ansi|--no-ansi  Force (or disable --no-ansi) ANSI output
  -n, --no-interaction  Do not ask any interactive question
  -v|vv|vvv, --verbose  Increase the verbosity of messages: 1 for normal output, 2 for more verbose output and 3 for debug

Available commands:
  completion              Dump the shell completion script
  help                    Display help for a command
  list                    List commands
 composer
  composer:install        Run the composer install command
 docker
  docker:build            Builds the docker image from source
  docker:compose          Run docker compose on the repository to boot up any container
  docker:compose:rebuild  Pulls down a new copy and rebuilds the image
  docker:pull             Docker pull and update an image
  docker:push             Pushes the docker image to the remote repository
 git
  git:clone               Clone from remote repo
  git:pull                Pull from github and update the local repo
 key
  key:generate            Generate an openssl key
 node
  node:install            Installs the repository and the docker container
  node:update             Updates the docker container, the repo and itself
 repo
  repo:install            Handles the entire installation of a REPOSITORY
  repo:slave              Continuous deployment keeps the local repo updated with the remote changes
  repo:update             Updates a repository that slept through changes

```

Run --help on any of the specific commands to see how to use the command.

## pipeline composer:install --help
```
Description:
  Run the composer install command

Usage:
  composer:install [<localdir>]

Arguments:
  localdir              The local dir to run composer install in [default: false]

Options:
  -h, --help            Display help for the given command. When no command is given display help for the list command
  -q, --quiet           Do not output any message
  -V, --version         Display this application version
      --ansi|--no-ansi  Force (or disable --no-ansi) ANSI output
  -n, --no-interaction  Do not ask any interactive question
  -v|vv|vvv, --verbose  Increase the verbosity of messages: 1 for normal output, 2 for more verbose output and 3 for debug

Help:
  Uses the composer.phar (Composer version 2.2.9 2022-03-15 22:13:37) which is a part of
  this package. There's no need to install composer on your server when using this project.
  
  1. If the project contains a composer.json file
  2. The following modified `composer install` command will be run:
  
  composer.phar install --working-dir=/path-to-repo/ --ignore-platform-reqs
  
```  
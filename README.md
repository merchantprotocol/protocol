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

You should then make protocol globally available by creating a symlink into a $PATH directory.

### Generate a key to be used with your remote git account

This command generates the key using default params, sets the permissions, adds it to be used in every ssh command and then returns the public key to be added to your remote git account.

```
# protocol key:generate
```

### Setting up a node for the first time

1. Use `git clone` standard command to pull down the repository of choice. If you're running a docker image, then the docker-compose.yml file should exist in this repo.

2. You'll want to setup a protocol.json file in your repository by running `protocol init` which will create the file based off of your repositories current state. You can then edit this file. Commit the protocol.json file into your remote repo.

3. You're now free to run `protocol start` from inside the local repo. (a) This command will update the local repo to match the remote. (b) place the current local repo into slave mode. (c) pull down the latest docker image. (d) run docker-compose rebuild in the local dir.

    At this point your node should be fully operational.

4. Recovering your repo when it reboots. `protocol restart <local>` should be run anytime the server is rebooted. This command can be run from any location, making it ideal to run from a cron job. You can do that by installing the command into your crontab. `@reboot protocol restart <local>`

4. When you're ready to boot down the server you can do so by running `protocol stop` from within the repo.

## ./protocol list

```
Protocol 0.2.0

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
  help                    Display help for a command
  init                    Creates the protocol.json file
  list                    List commands
  start                   Starts a node so that the repo and docker image stay up to date and are running
  stop                    Stops running slave modes
 docker
  docker:build            Builds the docker image from source
  docker:compose          Run docker compose on the repository to boot up any container
  docker:compose:down     shuts down docker
  docker:compose:rebuild  Pulls down a new copy and rebuilds the image
  docker:pull             Docker pull and update an image
  docker:push             Pushes the docker image to the remote repository
 git
  git:pull                Pull from github and update the local repo
 key
  key:generate            Generate an openssl key
 repo
  repo:slave              Continuous deployment keeps the local repo updated with the remote changes
  repo:slave:stop         Stops the slave mode when its running

```

Run --help on any of the specific commands to see how to use the command.

#### Sample json file

```json
{
    "name": "Datamelt",
    "git": {
        "username": "jonathonbyrdziak",
        "password": "",
        "key": "",
        "remote": "git@github.com/org/remote-repo.git"
    },
    "docker": {
        "image" : "byrdziak/merchantprotocol-webserver-nginx-php7.4:initial",
        "username" : "",
        "password" : "",
        "local" : "../docker-webserver-nginx-php7.4-fpm/"
    }
}
```
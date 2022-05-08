# PROTOCOL - A Command Line Tool for Highly Available Applications

PROTOCOL is a command line tool designed to help you keep a highly available application in sync.

## USE CASE - CI/CD

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

## USE CASE - Multiple Configuration Environments

Let's say you have multiple production nodes, multiple staging servers for each of your new feature branches, and every developer has their own development environment on their localhost. Maybe your CI/CD even has it's own testing configurations that are deployed during automated testing.

That's a lot of different configuration environments that could all demand their own configuration setup.

On top of that complexity you might even have multiple configurations files for your docker container, or your nginx configs, or your cron configs, or multiple configurations files for your application.

That could be a lot of different configuration files for each environment.

Like most people you probably don't want to commit your secrets into the application repo itself, specially if you're working on an open source project. You also don't want to be overwriting other peoples configurations and other environments configurations by committing them to the application repository.

### So what's the solution?

This original solution was designed by Github, and we at Merchant Protocol have fully developed the idea.

We've decided to use a git repository so that our configuration files are backed up in case a node goes down, and the only set of configuration files are not stored on the nodes. Having our config files in a git repo also gives us the added benefit of being able to see a history of our configuration changes, allowing us to rollback configurations and attach them to the incremental changes of the application repo.

A git repo also gives us the backbone of managing multiple configuration files and even multiple configuration environments when we introduce branches. This is all compatible with Github, Gitlab, Gitea and any other remote git repo management system you might use.

So our architecture is simple. We use a git repo to house all of our configurations files. The branches are named after each of the different types of environments. Developers can even create and backup their own custom configurations.

We use simple symbolic linking of the application files from the config repo into the application repo.

And `Protocol` manages the entire process for us. Protocol even provides us with the added benefit of `slave mode` which will keep our production environments in sync with a remote repo, or disable slave mode and pin your configuration state to the application commits.

Using `protocol config:init` will create your new configurations directory and initialize it for you. Just create a remote repo on your platform of choice and provide it with the remote url when asked.

You can now use `protocol config:cp` or `protocol config:mv` to move your configurations files into your new config repo. The config files will be stored in .gitignore on your application repo and symlinked from the config repo to the application repo.

`protocol config:save` will commit your changes and push your config repo.

You can easily `protocol config:new` create a new config environment and switch between them with `protocol config:switch`.




## System Requirements

- git
- php 7.4
- composer (optional)
- docker
- docker-compose

## Installation

```
# sudo curl -L "https://raw.githubusercontent.com/merchantprotocol/protocol/master/bin/install" | bash
```

If you want to install protocol independently, you can just clone down the repo

```
# git clone https://github.com/merchantprotocol/protocol.git $HOME/protocol
# chmod +x $HOME/protocol/protocol
# protocol self::global
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
Protocol 0.3.0

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
  exec                    Enters the container
  help                    Display help for a command
  init                    Creates the protocol.json file
  list                    List commands
  restart                 Used within a crontab to restart a node
  start                   Starts a node so that the repo and docker image stay up to date and are running
  stop                    Stops running slave modes
 config
  config:cp               Copy a file into the configurations repo.
  config:env              Set the global environment for the server
  config:init             Initialize the configuration repository
  config:link             Create symlinks for the configurations into the application dir
  config:mv               Move a file into the config repo, delete it from the app repo and create a symlink back.
  config:new              Create a new environment
  config:refresh          Clears all links and rebuilds them
  config:save             Saves the current environment to the remote
  config:slave            Keep the config repo updated with the remote changes
  config:slave:stop       Stops the config repo slave mode when its running
  config:switch           Switch to a different environment
  config:unlink           Remove symlinks for the configurations in the application dir
 docker
  docker:build            Builds the docker image from source
  docker:compose          Run docker compose on the repository to boot up any container
  docker:compose:down     shuts down docker
  docker:compose:rebuild  Pulls down a new copy and rebuilds the image
  docker:logs             Show the docker container logs
  docker:pull             Docker pull and update an image
  docker:push             Pushes the docker image to the remote repository
 git
  git:pull                Pull from github and update the local repo
  git:slave               Continuous deployment keeps the local repo updated with the remote changes
  git:slave:stop          Stops the slave mode when its running
 key
  key:generate            Generate an openssl key
 nginx
  nginx:logs              Show the nginx logs within the container

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
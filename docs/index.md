## Protocol the Command Line Tool

It just takes too long to repetitvely setup a new web server and then setup continuous deployment. Setting up a new server is easy, sure, but I don't want to waste my precious day setting up a new server and app if I don't have to. 

Then if I want continuous deployment.. there are lots of tools, but all are super complex for my needs, therefore taking way too long to setup. I want something simple, fast and reliable.

Oh and then don't forget having a VPS go down and losing your configuration files! Then you've got to set it all up again, not to forget trying to remember what the configurations were...

I'm done with those days!

Introducing Protocol, the Command Line Tool for PHP web apps.

With this tool you can quickly configure any php repository to deploy with ease on your server. Enable slave mode and all updates to your repository branch will get pushed out to production. It also manages the configuration files for multiple environments in a separate repository, making it easy to push configuration changes to hundreds of nodes.

This tool is becomming a valuable asset in my DevOps arsenal.

- It's perfect for setting up continuous deployment or continuous integration pipelines.
- Makes it easy to keep hundreds of nodes up to date in a cluster, even surviving a reboot!
- Manage production, staging and local development configuration files with ease.
- Makes it easy for new developers to the project to get up and running with little effort.
- Save time launching new web servers and applications

### Getting Started Quick Start Guide

In this quick start tutorial I'm going to show you how to deploy simple HelloWorld.php file to a production web app on your local machine.

#### 1. Installation

Open terminal and run the installation command:

```
sudo curl -L "https://raw.githubusercontent.com/merchantprotocol/protocol/master/bin/install" | bash
```

This will install all dependencies and the protocol command line tool on MacOS or Ubuntu.

You should now be able to run the following command to confirm that it's been installed

```
protocol -v
```

#### 2. Create A New Project Folder

If you already have an application in mind, then skip this step.

```
mkdir helloworld
cd helloworld
```

Add an index.php script

```
echo "<?php phpinfo();" > index.php
```

And lastly make this a git repository

```
git init
```

Make sure your local git folder is connected to a remote repository:

```
git remote add origin git@github.com:merchantprotocol/helloworld.git
```

If you haven't committed your local changes because you're following this guide literally, then do that now.

```
git add -A
git commit -m 'initial commit'
git push origin master
```


#### 3. Configure Your Project With Protocol

Now we want to initialize the project with Protocol:

```
protocol init
```

This is going to add a protocol.json file into your repository which contains defaults for managing your web app.

#### 4. Enabling CI/CD Mode

There's no reason you'd actually want to do this on your local machine that I can imagine. But if you're ready to setup this repository location as a slave to the remote matching branch, then follow this step.

This command will activate the current repository as a slave to its master.

```
protocol git:slave
```

You can now run the status function to see that slave mode is running

```
protocol status
```

You can go test the slave mode by adding a file to the remote repository. Within 10 seconds you should see that file appear within your local repo. The great thing about this slave mode is that it does not wipe out any changed files within the current local repo directory. The files will only be changed if you overwrite them with changes from the remote (master) repository.

If the local repository gets changed to the extent that it gets off track from the master repo, then it will disconnect slave mode.

To disable slave mode simple run:

```
protocol git:slave:stop
```

Now `protocol status` will show you that slave mode is stopped.

#### 5. Managing and Starting A Docker Container

If your repository DOES NOT contain a `docker-compose.yml` file then here's a sample one:

```
sudo curl -L "https://raw.githubusercontent.com/merchantprotocol/docker-nginx-php7.4-fpm/master/example/simple-docker-compose/docker-compose.yml" > docker-compose.yml
```

The `byrdziak/merchantprotocol-webserver-nginx-php7.4:initial` docker container called in this compose file is a fully functional php web server running nginx and php-fpm7.4. We use this on all of our PHP projects, you can customize this container for the specifics of each repo [Docker Nginx Server](https://github.com/merchantprotocol/docker-nginx-php7.4-fpm).

To boot up docker, all you need to do is run the following command and your docker container will be booted:

```
protocol docker:compose
```

You can now check the status with `protocol status` or `docker container ls` to see that it's running. When you're ready to stop it you can run:

```
protocol docker:compose:stop
```

#### 6. Managing Your Configuration Files

Configuration files are typically dropped into a web server and forgotten about... that is until the server crashes or you need to create a new app environment for a new developer or a new staging system.

Protocol has a unique method for managing configuration files. You can read more about the philosophy of it in the configuration documentation, but the basic premise is that we manage the configuration files in a separate repository form the codebase. Each branch is a different environment and any number of configuration files can be stored in the branch. Protocol will load the proper environment, or branch, and then symbolicly link all of the configuration files into the local code repo prior to starting the docker container. Super cool huh!

To setup a new configuration environment we first need to set the environment for this server.

```
protocol config:env localhost
```

You can really name the environment whatever you want, in this example I named it localhost. Our developers always append their github handle onto the end of the environment `localhost-jonathonbyrdziak`. This way we can see everybodies environment configurations, and easily help new developers get up to speed by allowing them to copy another developers environment.

Now configure the configurations by running:

```
protocol config:init
```

This will ask you for the remote configuration repo link. Let's create a new repo called 'helloworld-config' and give protocol the git link. Protocol will create your new environment and ask if you want to save your changes by PUSHing them to the remote repo. Say Yes.

You now have your configuration repo configured. 

Let's assume that you have a configuration file called `.env.` in your repository. Tracked or untracked, doesn't matter. If you don't have this file go ahead and create it `echo "; test config file" > .env`

We want to copy this file into the configuration repo and leave it in the current location.

```
protocol config:cp .env
```

You can now check your configurations folder to see if the file was moved.

```
ls -la ../helloworld-config
```

Once you've confirmed that the file has moved, you can now delete it from your local repo and it will only exist in the config repo.

```
rm .env
```

Ok, so this is how your configuration files will live. They will live in a separate configuration folder from your code repository. This allows us to switch between configuration environments easily, and even deploy multiple localhost configuration environments, one for each developer.

#### 7. Enabling a Configuration Environment

Ok, so we're on the localhost dev environment, still sitting inside of our primary code repo (not the config repo) and we're wondering how we're actually going to use the configuration files.

We're going to run this next command that symbolicly links the configuration files from the config directory into our codebase directory:

```
protocol config:link
```

And now if you run `ls -la` you'll see the .env file in the codebase repo begin symlinked to the config repo.

Your next problem/question is that the symbolic linking is probably not working inside of your docker container if you've tried to run docker to test this all out. What you'll need to do is add another mounted volume to the docker-compose.yml file that looks like this:

```
    volumes:
      - '.:/var/www/html:rw'
      - '../helloworld-config/:/var/www/helloworld-config:rw'
```

Notice the helloworld-config reference that mounts the config volume as a sibling to the html folder. This will allow the symlinks to be honored inside of your docker container. Protocol makes sure to create the links as relative and not absolute in order for this all to work seamlessly.

And to unlink we'd just run:

```
protocol config:unlink
```

You also have various other protocol methods for managing your configurations:

```
Protocol 0.3.0

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
```

#### 8. Start everything at the same time

Now of course we don't want to have to run all these commands at the same time. We'd much rather just run a single command to start the web application and one command to stop the web application. For that purpose we're just going to run:

```
protocol start
```

That's it. Here's what happens:

1. The latest changes are pulled down for the codebase repo
2. `Composer Install` is run to update any vendor dependencies
3. The codebase repo becomes a slave to its remote branch equivilant
4. The latest configuration env is pulled down.
5. The config repo becomes a slave to its remote branch equiv
6. The config repo is symbolicly linked into the codebase repo for the preconfigured environment
7. The latest changes are pulled down for the docker container
8. The docker container is booted up and rebuilt if necessary
9. `protocol status` is run to show the final outcome

### Support or Contact

Having trouble with Protocol? Contact us as [MerchantProtocol.com](https://merchantprotocol.com/) and we’ll help you sort it out.

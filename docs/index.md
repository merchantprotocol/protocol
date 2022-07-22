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





### Support or Contact

Having trouble with Protocol? Contact us as [MerchantProtocol.com](https://merchantprotocol.com/) and we’ll help you sort it out.

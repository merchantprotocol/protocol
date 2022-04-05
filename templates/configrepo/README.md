## Configurations repository 

### *DO NOT MERGE BRANCHES!*

Hosts configuration files for {{repository}}

### Use Case

Have you run into a situation where you have multiple nodes in production, a staging environment and multiple localhost development machines. Each individual developer has a different configurations file, and configurations are drifting between the nodes because you don't have them committed to the repository.

Your solution, commit the configurations files to the repo. But that only causes another problem, because now you've overwritten every single machine to use one configuration set. How to solve this:

Your solution is to create a configurations repository.

Each of the branches on this repo serve as a different environment. The files in this repository shall be copied into the application folder when making the application available. Each of this files should be specifically ignored in the applications .gitignore file.

This end result makes it possible to track the changes to your configurations files. Everybody knows of the central repository of where they should find the configurations for any environment. This also makes it easier when bringing new developers onto the team, to get them setup quickly.

You'll easily be able to switch an application between environments without changing the application repository. Multiple nodes can still be updated easily as they get their configurations from a centralized source.

### Could it get any easier?

With the use of [Protocol](https://github.com/merchantprotocol/protocol), our command line tool for managing application environments, you're able to easily specify the current environment for the machine. Protocol will automatically copy the files from the configurations repo into the application for you and monitor the configurations files for changes.

@see Protocol for further use documentation
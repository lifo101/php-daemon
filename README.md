## PHP Daemon Library

### Synopsis
Create robust and stable PHP multiprocess daemons with minimal boilerplate code. The core Daemon class handles the main
loop and events. The main loop can run at any frequency desired _(within the limits of PHP)_. 
Using `Tasks` and `Workers` the daemon can call methods on background processes seamlessly w/o worrying about 
managing forked children.

### Why write a daemon in PHP?
Obviously, writing robust, stable and long-running daemons in PHP is generally not a good idea. It's at least very
hard to do, and do well. I personally needed a daemon in PHP because I had an entire website framework built in Symfony
that needed a major back-end daemon. I wanted to be able to re-use all my front-end dependencies and entities w/o 
duplicating resources or configs.

While this library does everything it can to allow you to create a rock solid daemon, care must still be taken in your 
user-land code to keep things stable. 

### Requirements
- PHP 5.4.4+
- A POSIX compatible operating system (Linux, OSX, BSD)
- PHP [POSIX](http://php.net/posix) and [PCNTL](http://php.net/pcntl) Extensions

### Examples
See the [Examples Directory](examples/README.md) for examples you can run. 

### Features
- The `Event Loop` is maintained by the core `Daemon` class. All you have to do is implement one method `execute` that
  will get called every loop cycle. The loop frequency can be any fractional value in seconds. If set to 0, your 
  `execute` method will get called as fast as possible (_not normally recommended, unless your loop is doing some sort
  of blocking call, ie: listening on a socket, etc_).
- In just a few lines of code you can have parallel processes running in the background without having to manage those
  background processes. 
  - A `Task` allows you to call any method or callback in a background process. No communication is made between the
    background process and the parent. Tasks are meant for simple things, for example: Sending an email.
  - A `Worker` allows you to call any method on an object, or even just a simple callback like a `Task`. Workers
    can return a value back to the parent via a simple `return` statement in your worker method(s). Workers
    are maintained automatically and can have multiple children running at the same time, which is handled 
    transparently. Even if a worker dies or is killed by the OS the Daemon API will still return a result (or exception)
    to your code. The return value of a Worker is usually a `Promise` object. You can use the standard Promise methods
    like `then` or `otherwise` to act on the return value. Or you can register an "ON_RETURN" callback on the Worker.
    
    Workers use a [Mediator design pattern](https://en.wikipedia.org/wiki/Mediator_pattern) and use Shared Memory
    for it's messaging queue and data. _I might work on a second IPC class that uses sockets instead of SHM to provide
    an alternate choice_.
- Event Handling. The core `Daemon` has several events (see: [DaemonEvents](src/Lifo/Daemon/Event/DaemonEvent.php))
  that you can easily interface with by registering a callback. Some events have the means to change the behavior of 
  the daemon.
- Easy Signal Handling via the Event Dispatcher. To catch a signal you simply have to register a `ON_SIGNAL` callback 
  in your code. Your callback will be passed an `SignalEvent` with the signal that was caught.
- Simple `Plugin` architecture allows you to use and create your own plugins that can be injected into the Daemon. 
  Plugins can be lazily loaded.
  - A core plugin `FileLock` allows you to add a locking mechanism to prevent your daemon from running more than one
    instance at a time. Simply register the plugin in your daemon and the rest is automatic. A `ShmLock` is similar
    but uses Shared Memory to obtain a lock.
- Automatic restarting. The Daemon can automatically restart itself if it's runtime reached a configurable threshold 
  or if a fatal error occurred. 
- Built in logging. The `Daemon` has 3 basic logging methods: `log`, `error`, `debug`. All of these will write to the
  log file (if configured). If the log file is rotated, overwritten or deleted, the daemon will automatically detect 
  this and will continue to write to the new log file. The `DaemonEvent::ON_LOG` allows you to register a callback to 
  change the behavior too. User code can use the [LogTrait](src/Lifo/Daemon/LogTrait.php) to easily add native daemon 
  logging to their code.

### Credit
The basis for this library was inspired by the [PHP-Daemon](https://github.com/shaneharter/PHP-Daemon) library
from [Shane Harter](https://github.com/shaneharter) on GitHub. Unfortunately, his library was abandoned years ago, 
was written for PHP v5.3, had no namespacing, no package management or an auto-loader (ie: Composer). 

I choose to create an entirely new library instead of forking and modifying his original library for educational 
purposes. I also didn't agree with some of his methodologies. I do require some extra dependencies, but 
[Composer](http://getcomposer.org/) makes this a trivial issue.

---
_This library is in a fully working state. I've created a very complex daemon that has run for weeks w/o any memory 
 leaks or crashes. But this is still a **Work in Progress**!_

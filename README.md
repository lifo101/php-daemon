## PHP Daemon Library

### Synopsis
Create robust and stable PHP multiprocess daemons with minimal boilerplate code. The core Daemon class handles the main
loop an events. The main loop can run at any frequency desired _(within the limits of PHP)_. 
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
- PHP POSIX and PCNTL Extensions

### Credit
The basis for this library was inspired by the [PHP-Daemon](https://github.com/shaneharter/PHP-Daemon) library
from [Shane Harter](https://github.com/shaneharter) on GitHub. Unfortunately, his library was abandoned years ago, 
was written for PHP v5.3, had no namespacing, no package management or an auto-loader (ie: Composer). 

I choose to create an entirely new library instead of forking and modifying his original library for educational 
purposes. I also didn't agree with some of his methodologies. I do require some extra dependencies, but 
[Composer](http://getcomposer.org/) makes this a trivial issue.

---
_This library is in a fully working state. I've created a very complex daemon that has run for days w/o any memory 
 leaks or crashes. But this is still a **Work in Progress**!_

# kanti/ssh-tunnel

This is a simple SSH tunneling library for PHP.  
It allows you to create SSH tunnels to remote servers and use them to forward ports on your local machine to the remote server.


## Installation

You can install the package via composer:

```bash
composer require kanti/ssh-tunnel
```

## Usage

### tcp:
````php
$tunnel = new \Kanti\SshTunnel\SshTunnel(
    sshHost: "testServer", // uses the default ssh config ~/.ssh/config
    remote: \Kanti\SshTunnel\Remote::tcp(3306, '127.0.0.1'),
    localPort: null, // if empty will auto select a local port for you (recommended)
);
$localPort = $tunnel->start();
$connection = new \PDO('mysql:host=127.0.0.1;port=' . $localPort, 'user', 'password');
````

### linux socket as tcp port:
````php
$tunnel = new \Kanti\SshTunnel\SshTunnel(
    sshHost: "testServer", // uses the default ssh config ~/.ssh/config
    remote: \Kanti\SshTunnel\Remote::socket('/var/run/mysqld/mysqld.sock'), // can also expose the mysql socket as tcp port.
    localPort: null,
);
$localPort = $tunnel->start();
$connection = new \PDO('mysql:host=127.0.0.1;port=' . $localPort, 'user', 'password');
````

### advanced usages:
````php
use \Kanti\SshTunnel\SshTunnel;

// tunnel to connect to a remote MySQL server
$tunnel = new SshTunnel(
    // set custom ssh settings:
    sshUser: 'user',
    sshHost: "1.1.1.1",
    sshPort: 221,
    remote: \Kanti\SshTunnel\Remote::tcp(3306, '192.168.0.2'),
    localPort: null,
);
$localPort = $tunnel->start();

// connect to mysql
$connection = new \PDO('mysql:host=127.0.0.1;port=' . $localPort, 'user', 'password');

// do something with the connection

$tunnel->stop(); // stops the tunnel
// OR 
unset($tunnel); // also stops the tunnel (can be disabled in the constructor disconnectAfterTunnelDestroyed: false)
// OR
// you can stop the PHP Script, that will also stop the tunnel (via inactivity timeout)
````

## How does it work?

The library uses the `ssh` command line tool to create the tunnel.  
It uses the control_socket feature of ssh to keep the connection open and reuse it for multiple tunnels (if needed).  
The tunnel is created in the background.   
By default it will not output anything to the console and will exit after 5s (option: `autoDisconnectTimeout: '10s'`) of inactivity.  
If you want to see the output of the tunnel you can set the `debugMasterOutput: true` in the constructor.  

## Linting

``` bash
composer install
grumphp run
```

## Author

````php
made with ❤️ by Kanti (Matthias Vogel)
````

# Command Line Installer

Idno allows you to install Idno via the command line, this can be handy for scripted installs, using non-MySQL database backends, or when installing over a terminal connection to a remote server.

# The Basics

Go in to ```/warmup/CLI```, and you'll see ```CLIInstaller```.

To install a brand new Idno using an interactive install simply run 

```
./CLIInstaller install
```

# The Manifest

A manifest is used to pass configuration options to the CLI installer so that you won't be prompted for them. This is useful for scripting installs.

First, generate a manifest template:

```
./CLIInstaller generate-manifest /path/to/manifest
```

Next, edit that file and fill in the appropriate option.

Now, you are able to pass these options to the installer:

```
./CLIInstaller install config.ini /path/to/manifest
```

The first argument names the file to write in `configuration/`. It must be `config.ini`: Idno no longer reads per-domain configuration files (`configuration/<domain>.ini`), so the installer refuses any other name.

# Other options

The CLI installer has a few other options that might be useful:

## Checking requirements

If you just want to check that your server has the necessary requirements to run Idno, you can execute:

```
./CLIInstaller.php check-requirements
```

## Generate config from manifest

If you want to generate a config file, but without going through the full install (useful in a provisioning script), you can use the following command to do so

```
./CLIInstaller.php makeconfig /path/to/manifest 
```

This will output the new config to stdOut. Save it as `configuration/config.ini`.
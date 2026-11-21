# Coding Standards

## The basics

* American spelling is preferred for method and variable names. eg, "color" is better than "colour" and "sanitize" is better than "sanitise".
* Lay your code out as beautifully as you can. We're in favor of beautifiers!
* Legibility over cleverness please!

## Clarity and avoiding fragile code

We like easily readable code, and we also like code that is hard to break.

* Think carefully about using the ternary operator ``` $var == 'foo' ? true : false ```, as they can often result in hard to follow code. 
  * Nesting these is almost always a bad idea, since PHP's behaviour in this situation is far from obvious. If you find yourself needing to do this, you probably need to think about doing things a different way
  * Think carefully about using the ternary operator with all but the most trivial string concatenations, as it often leads to fragile and hard to read code if a bracket gets missed. E.g. ``` $foo = 'SELECT * from bar where foo="' . $var == 'foo' ? 'foo' : 'bar' . '" limit 5' ``` will not necessarily do what you think it will, and can be hard to spot. 
* Always try to use brackets in if statements, even if there's only one line of enclosed logic, as not doing so leads to fragile code: So

```
if ($foo) {
  echo 'bar';
}
```
is good, but

```
if ($foo)
  echo 'bar';

```

is bad.

## Naming conventions

* Namespaces should be UpperCamelCase, with a matching /UpperCamelCase/ file path
* Classes should use UpperCamelCase, with a matching UpperCamelCase.php file.
* All method names should use lower camelCase 
* All variable names should use snake_case.

## Indentation

* We favor *spaces over tabs*. Please set your IDE to *four* spaces per tab.
* Multiline associative arrays, each item should appear on a new line, indented, e.g:

```
$var = [
    'example1' => 'value1',
    'example2' => 'value2',
];
```
!!! note "Note"
    Notice the final comma... adding this makes future updates easier, and less fragile, and so is encouraged.

* Switch and cases should also be embedded, with a space after each ```switch``` and ```case```, e.g.:
```
switch ($var) {
    case 'example1':
        some_function();
        break;
    case 'example2':
        another_function();
        break;
}
```
* In general, when in doubt, space it out.

## Variables

* You should try and make use of [type hinting](https://www.php.net/manual/en/functions.arguments.php#functions.arguments.type-declaration) in your function arguments.
* Since Idno now no longer supports PHP < 7, you should also try and make use of return type hinting where appropriate (advanced warning, in future we will turn on strict type checking for this!)

## Error handling

* Notice errors are errors, please develop your code with ```pedantic_mode = true;``` set in your config.ini
* Unless there's a VERY good reason you can explain (and should explain) in comments, never use the '@' error suppression operator.

## Fetching URLs

* Use `\Idno\Core\Webservice` for outbound HTTP requests, so that the site's proxy and TLS settings apply. Don't call curl, `file_get_contents()` or `fopen()` on a URL directly.
* If the URL was supplied by a remote party (for example a webmention source, an ActivityPub actor or inbox, or a file a Micropub client asks the site to download), fetch it with `\Idno\Core\Webservice::safeSend($verb, $url, $params, $headers)`. It refuses URLs that are not HTTP or HTTPS, and hosts on the server itself or its private network, and it applies the same check to every redirect, following five at most. A refused request returns empty `'content'` and a message in `'error'` (`'response'` is 0 for a refused address, and the last redirect's status when there are too many).
* `Webservice::get()`, `post()` and the other verbs follow redirects without these checks. Only use them for URLs that the site's administrator or its users chose.
* HTTPS certificates are verified. Don't turn verification off in code; a site that really needs to can set `disable_ssl_verify` in its config.ini.

## Comments

We like comments, and when in doubt, you probably should write a comment! That doesn't mean writing a comment for every line, but 
we'd like to know your thought process behind what you wrote, and why you did something a certain way. 

This will help future developers, maybe even you, so be kind to your future self!

### phpDocumentor blocks

Use [phpDocumentor blocks](http://docs.phpdoc.org/references/phpdoc/basic-syntax.html) for every method, *except* inherited methods, where they are optional. Use your best judgment here.

* Explain the purpose of the method or class as clearly as you can. For example, "loads ClassName by property_name" is bad; 
  "loads objects that have a property called property_name" is much better.
* Explain what object properties are, and what they're for.
* "Just read the code" isn't a good idea, and we don't like it.
* That said, clear, simple code is good :)

## PHP Files

* Use Unix line endings
* End your file with a single blank line
* You should never end your .php file with ```?>```, as this leads to fragile code.

## PHP tags

* Open your PHP blocks with ```<?php```, and close with ```?>```
* Shorthand ```<?=``` is ok in templates

# Commit messages

After this page you will be able to write a commit message that tells the next developer what changed and why.

Commit messages are the main way developers tell each other what they did. Write yours for someone who is trying to understand, or debug, your change a year from now.

## Give a subject line

This subject line should give a brief note of WHAT you changed, e.g.

```
Adds reset button to post form 
```

### Add your project to the subject

If your commit is part of a logical sub project, add it to your subject, e.g.

```
PHP 8.3 compatibility: Changing out sizeof for count
```

Use your best judgement, but think about how to find relevant commits when scanning a change log. Recent commits in the repository often start with a short type prefix instead, such as `fix:` or `docs:`; either style is fine as long as the subject says what changed.

## Explain why

Unless immediately obvious, because the "why" part was included in the subject e.g. 
```
Updating array format to match style guidelines
``` 

After a blank line after the subject, you should normally include a "why" part, so for example:

```
Adds reset button to post form 

Users often had to manually reset the contents of a form, so a simple reset was added
for convenience. 
```

## Explain the problem you're fixing

Mostly you're trying to fix something, so again, briefly explain the problem and the solution you came up with, e.g. 

```
Adding the current user UUID to JSON object

The code was previously not returning the current user UUID, which meant that the 
subsequent JavaScript needed to make a separate lookup, which was inefficient. 

The solution was to return the UUID as part of the original request, and update 
the calling code to make use of it.
```

## Say HOW you fixed it

Where appropriate, you should explain the how in your commit, e.g.

```
Adding the current user UUID to JSON object

The code was previously not returning the current user UUID, which meant that the 
subsequent JavaScript needed to make a separate lookup, which was inefficient. 

The solution was to return the UUID as part of the original request, and update 
the calling code to make use of it. This was achieved by pulling the UUID out of 
the user object stored in the current session.
```

## Reference tickets and changes

If you're referencing tickets, be sure to mention them, either in the body of the commit or at the end, e.g.

```
Adding the current user UUID to JSON object

The code was previously not returning the current user UUID, which meant that the 
subsequent JavaScript needed to make a separate lookup, which was inefficient. 

The solution was to return the UUID as part of the original request, and update 
the calling code to make use of it. This was achieved by pulling the UUID out of 
the user object stored in the current session.

Fixes #2345
```

And if a problem was introduced by a specific change request, mention that in your message as well.

## Misc guidelines 

* If your subject line contains the word "and", your commit is probably too big. Split it.
* Don't just substitute a description with a link, although links are handy for context.
* With all this in mind, use common sense, and think "legibility"

!!! note "Credits"
    This document borrows heavily on the [WordPress commit message guidelines](https://make.wordpress.org/core/handbook/best-practices/commit-messages/), so if there's something missing here, definitely follow their guidelines.

## Where next

* [Submitting a pull request](pull-requests.md)

# Pull Requests

Once you're ready to send code back to the Idno project, you need to submit a pull request to the [core GitHub repository](https://github.com/idno/idno), against the `dev` branch. The pull request template includes a checklist; please work through it before asking for a review.

## Branch names

When forking the project to work on your own modification, it's a good idea to incorporate both your name and the work being undertaken in your branch. For example, if Ben Werdmuller was adding a unit test for user deletion, the branch might be called `ben-unit-test-user-deletion`. Branches with names like `patch-23439` should be avoided.

## Pull request naming

Please see [advice on writing commit messages](commit-messages.md), which equally apply for pull request names and descriptions.

## Reviews

Every pull request, including those by Idno's core developers, needs to be reviewed by another developer. We recommend using the [GitHub CLI](https://cli.github.com/) to make this easier from the command line. For example, to submit a pull request with `benwerd` as the reviewer, you would just enter `gh pr create --reviewer benwerd`.

Please note that even if there is a review requested from a particular individual, anyone is free to give a review! Reviews from the wider community are very much appreciated. GitHub makes it easy to reject, request changes to, or approve a pull request through a code review.

## Merging

Once a pull request has had an approved review, **the original author should merge it and delete the branch**. This way, should any code conflicts have arisen between submission and review, it is the author's responsibility to clean it up. Also, it feels good to hit the button to merge your code into the core codebase!  

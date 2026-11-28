# Files and uploads

After this page you will be able to take a file from a form, attach it to an entity, show it, and replace or
remove it, without losing a member's work when an upload goes wrong.

The Photo and Media plugins are the working examples. This page walks through what they do.

## How files are stored

A site stores uploaded files in its **file system**, which is a folder on disk for most sites (the `uploadpath`
setting). You don't deal with paths. You hand a file to Idno, get back a stored file, and **attach** it to an
entity. The entity then carries a list of its attachments, each with an address under `/file/`.

Attached files follow their entity:

* A file is served only to someone who may read the entity it is attached to. A photo in a private post is
  private.
* When the entity is deleted, its attached files are deleted.

## The form

A form that carries a file needs `enctype="multipart/form-data"`:

```php
<form action="<?php echo $vars['object']->getURL() ?>" method="post" enctype="multipart/form-data">

    <?php echo $this->__([
        'name'   => 'picture',
        'accept' => \Idno\Core\Upload::imageAccept(),
    ])->draw('forms/input/file'); ?>

    <?php echo $this->__([
        'kinds'    => \Idno\Core\Upload::typeList(),
        'several'  => false,
        'required' => '',
    ])->draw('forms/upload/limits'); ?>

    <!-- the rest of the form -->
</form>
```

`forms/upload/limits` does two useful things. It tells the member which kinds and sizes of file the site
accepts, and it checks the chosen file in the browser, so that a file the server would refuse is caught before
anything is sent. Its variables:

| Variable | Meaning |
|---|---|
| `kinds` | The kinds of file accepted, as a phrase. `Upload::typeList()` gives the list of picture formats; pass an array of names for your own, such as `Upload::typeList(['MP3', 'WAV'])`. |
| `several` | `true` if the form takes more than one file |
| `required` | If not empty, the form must carry a file, and this text is shown when it does not |

## Why uploads need care

PHP has two size limits, and it enforces both silently:

* A file larger than `upload_max_filesize` is dropped. Your code sees an entry in `$_FILES` with an error code
  and no file.
* A request larger than `post_max_size` is dropped **whole**: no fields, no files, no form token.

Neither is an error as far as PHP is concerned. If you don't look, you save a post without its file and tell the
member nothing.

Idno handles the second case for every page: `Page::post()` answers such a request with status 413 and a message
about the size. The first case is yours to handle, and `\Idno\Core\Upload` gives you the words.

## Receiving the file

In your entity's `saveDataFromInput()`:

```php
function saveDataFromInput()
{
    $page    = \Idno\Core\Idno::site()->currentPage();
    $session = \Idno\Core\Idno::site()->session();

    // 1. May the member change this at all? Ask before any file is stored or removed.
    if (!empty($this->_id) && !$this->canEdit()) {
        return false;
    }

    // 2. Look at the upload before doing anything with it
    $upload = $_FILES['picture'] ?? null;
    $sent   = $upload && $upload['error'] !== UPLOAD_ERR_NO_FILE;

    if ($sent) {
        if ($upload['error'] !== UPLOAD_ERR_OK) {
            $session->addErrorMessage(\Idno\Core\Upload::errorMessage($upload['error'], $upload['name']));

            return false;
        }
        if (!\Idno\Core\Upload::isShowableImage($upload['tmp_name'], $upload['name'])) {
            $session->addErrorMessage('That file is not a picture this site can show.');

            return false;
        }
    }

    // 3. Now the ordinary fields
    $this->title = $page->getTextInput('title');
    $this->setAccess($page->getTextInput('access'));

    // 4. Store the file and attach it
    if ($sent) {
        $this->deleteAttachments();    // Replace the picture this entity had, if any

        $file = \Idno\Entities\File::createFromFile(
            $upload['tmp_name'], $upload['name'], $upload['type'], true
        );
        if (!$file) {
            $session->addErrorMessage('The picture could not be stored.');

            return false;
        }
        $this->attachFile($file, true);
    }

    return (bool) $this->publish();
}
```

The order matters:

1. **Permission first.** Storing and removing files happen before `save()`, so `save()`'s own permission check
   comes too late to protect them.
2. **Judge every file before you store any.** Read each entry's `error` code. `Upload::errorMessage($code, $name)`
   turns a code into a sentence a member can act on, with the site's limit in it. It returns an empty string for
   `UPLOAD_ERR_NO_FILE`, because only your form knows whether a file was required.
3. **Check what the file is, not what it says it is.** The name and the type in `$_FILES` are chosen by the
   sender. `Upload::isShowableImage()` looks at the file itself.
4. **Then store.** `File::createFromFile($path, $name, $mime_type, true)` stores the file and returns it;
   `attachFile($file, true)` adds it to the entity. The second argument of `attachFile()` says the file will be
   shown in a page, which makes Idno neutralize file names that a browser could run as a script.

When `saveDataFromInput()` returns `false`, send the member back to the form with what they typed. The Photo
plugin's `Pages/Edit.php` shows how.

For several files in one field (`name="photo[]"`), `\Idno\Core\Input::getFiles('photo')` gives you a tidy list
with one entry per file.

!!! warning "Don't filter on tmp_name"
    It is tempting to skip entries of `$_FILES` whose `tmp_name` is empty. Those are exactly the files PHP
    dropped for being too large. Read `error` first.

## Showing the file

`getAttachments()` returns the entity's attachments. Each is an array with `url`, `filename`, `mime-type` and
`length`:

```php
<?php foreach ($vars['object']->getAttachments() as $attachment) {
    $src = \Idno\Core\UrlValidator::httpUrl($attachment['url']);
    if ($src === '') {
        continue;
    }
    ?>
    <img src="<?php echo htmlspecialchars($src) ?>"
         alt="<?php echo htmlspecialchars((string) $vars['object']->title) ?>">
<?php } ?>
```

## Thumbnails

`\Idno\Entities\File::createThumbnailFromFile($path, $filename, $max_dimension = 800, $square = false)` stores a
smaller copy of a picture and returns its id.

A thumbnail made this way is a stored file but not an attachment, so Idno does not know it belongs to your
entity. Tell it, by overriding `getAttachedFileIDs()` to return the ids of **every** file your entity owns. Then
the thumbnail is as private as the post. `IdnoPlugins/Photo/Photo.php` does this.

## Replacing and removing

| Method | What it does |
|---|---|
| `$entity->deleteAttachments()` | Removes every attached file from storage and from the entity |
| `$entity->deleteAttachment($id)` | Removes one |

Deleting the entity removes its attachments for you.

## Limits you can ask about

```php
\Idno\Core\Upload::maxFileSize();      // Largest single file, in bytes
\Idno\Core\Upload::maxRequestSize();   // Largest whole request, in bytes
\Idno\Core\Upload::formatSize($bytes); // "2 MB"
```

These come from PHP's settings on the server.

## Files from other sites

If your plugin stores a file from an address someone gave it (a picture to import, say), fetch it with
`\Idno\Core\Webservice::safeSend()`, never with `file_get_contents()` on the address. See the
[security checklist](security.md#4-be-careful-what-you-fetch).

## Letting other plugins object

The Photo and Media plugins trigger the event `file/upload` before they accept a new file, and stop if the
answer is `false`. A plugin that limits storage listens for it. Do the same if you want yours to respect such
limits:

```php
if (!\Idno\Core\Idno::site()->events()->triggerEvent('file/upload', [], true)) {
    return false;
}
```

If your plugin stores files, also implement `getFileUsage()` in [your plugin class](class.md#getfileusageuser-false),
so the site can report how much space it uses.

## Where next

* [Access control](access.md)
* [Security checklist](security.md)
* [Content types](content-types.md)

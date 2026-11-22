<div class="h-entry result-500">
    <div class="row" style="margin-bottom: 2em; margin-top: 4em">

        <div class="col-md-offset-1 col-md-10">
            <h1 class="p-name">
                <?php echo \Idno\Core\Idno::site()->language()->_('Something went wrong.'); ?>
            </h1>
        </div>
    </div>
    <?php if (!empty($vars['exception'])) { ?>
    <div class="row">
        <div class="col-md-offset-1 col-md-10">
            <p class="p-summary"><?php echo htmlspecialchars(\Idno\Core\ExceptionMessage::forVisitor($vars['exception']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></p>
            <p>
                <?php echo \Idno\Core\Idno::site()->language()->_('<a href="#" onclick="window.history.back();">Click here to try again</a> or <a href="%s">click here to go back to the homepage</a>.', [\Idno\Core\Idno::site()->config()->getDisplayURL()]); ?>
            </p>
            <?php if (($debug = \Idno\Core\Idno::site()->config()->debug) && (!empty($debug))) { ?>
                <p>
                    <small><a href="#" onclick="$('#details').show(); return false;"><?php echo \Idno\Core\Idno::site()->language()->_('Click here to see the technical details.'); ?></a></small>
                </p>
                <div id="details" style="display:none">
                    <pre>
                <?php echo htmlspecialchars((string) $vars['exception'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                    </pre>
                </div>
            <?php } ?>
        </div>
    </div>
    <?php } else { ?>
    <div class="row">
        <div class="col-md-offset-1 col-md-10">
            <p class="p-summary"><?php echo \Idno\Core\Idno::site()->language()->_('Oh no! Something went wrong.'); ?></p>
        </div>
    </div>
    <?php } ?>
</div>
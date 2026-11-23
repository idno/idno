<?php if (\Idno\Core\Idno::site()->currentPage()->getInput('q')) {
    // Search term
    ?>
    <div class="row" style="margin-top: 5em">
        <div class="col-md-6 col-md-offset-3">
            <div class="no-content explanation">
                <p>


                    <?php echo \Idno\Core\Idno::site()->language()->_("We couldn't find anything that matches your search."); ?>

                </p>
            </div>
        </div>
    </div>
    <?php
}

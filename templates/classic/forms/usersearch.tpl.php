<?php
if (empty($vars['name'])) {
    $vars['name'] = 'user-search';
}

global $input_id;
if (!isset($vars['id'])) {
    $input_id ++;
    $vars['id'] = $vars['name'] . "_$input_id";
}

if (empty($vars['source-url'])) {
    $vars['source-url'] = \Idno\Core\Idno::site()->config()->getDisplayURL() . 'search/users/';
}

if (empty($vars['render-template'])) {
    $vars['render-template'] = 'forms/components/usersearch/user';
}
?>
<div id="<?php echo $vars['id']; ?>" class="users-search <?php
if (!empty($vars['class'])) {
    echo $vars['class'];
}
?>"> 
    <form action="<?php echo $vars['source-url']; ?>">
        <div class="search-controls">
            <div class="input-group">
                <input name="query" type="text" class="form-control" placeholder="<?php echo \Idno\Core\Idno::site()->language()->_('Search by name, email address, or username'); ?>" aria-describedby="search">
                <span class="input-group-btn">
                    <button type="submit" class="btn btn-primary" id="search"><i class="fa fa-search"></i></button>
                </span>
                
                
                <?php echo
                $this->__(
                    [
                    'name' => 'template',
                    'value' => $vars['render-template']
                    ]
                )->draw('forms/input/hidden');
                ?>

            <?php echo
            $this->__(
                [
                'name' => 'sort',
                'value' => 'created'
                ]
            )->draw('forms/input/hidden');
            ?>

            <?php echo
            $this->__(
                [
                'name' => 'order',
                'value' => 'desc'
                ]
            )->draw('forms/input/hidden');
            ?>

            <?php echo
            $this->__(
                [
                'name' => 'offset',
                'value' => 0
                ]
            )->draw('forms/input/hidden');
            ?>

            <?php echo
            $this->__(
                [
                'name' => 'limit',
                'value' => 100
                ]
            )->draw('forms/input/hidden');
            ?>
            <?php echo
            $this->__(
                [
                'name' => 'count'
                ]
            )->draw('forms/input/hidden');
            ?>
            </div>
        </div>
    </form>

    <div class="search-sort hidden"> <!-- Not actually possible atm with Idno's native object search functions -->
        <div class="btn-group sort">
            <button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                Sort <span class="caret"></span>
            </button>
            <ul class="dropdown-menu">
                <li><a href="#" data-sort="created"><?php echo \Idno\Core\Idno::site()->language()->_('By creation date'); ?></a></li>
                <li><a href="#" data-sort="name"><?php echo \Idno\Core\Idno::site()->language()->_('By name'); ?></a></li>
            </ul>
        </div>

        <div class="btn-group order">
            <button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                Order <span class="caret"></span>
            </button>
            <ul class="dropdown-menu">
                <li><a href="#" data-sort="asc"><?php echo \Idno\Core\Idno::site()->language()->_('Ascending'); ?></a></li>
                <li><a href="#" data-sort="desc"><?php echo \Idno\Core\Idno::site()->language()->_('Descending'); ?></a></li>
            </ul>
        </div>
    </div>

    <div class="results pane">

    </div>


    <div class="pager">
        <ul>
            <li class="newer pagination-disabled"><a href="#" title="Previous" rel="prev"><span>&laquo; <?php echo \Idno\Core\Idno::site()->language()->_('Prev'); ?></span></a></li>
            <li class="older pagination-disabled"><a href="#" title="Next" rel="next"><span><?php echo \Idno\Core\Idno::site()->language()->_('Next'); ?> &raquo;</span></a></li>
        </ul>
    </div>
</div>
<?php
foreach (['source-url', 'control-id', 'render-template', 'name', 'id'] as $variable) {
    unset($this->vars[$variable]);
}
?>

<script>

    var form = $('#<?php echo $vars['id']; ?>');
    var form_actual = form.find('form');

    var query = form.find("input[name='query']");
    
    function executeSearch(form) {

        var query = form.find("input[name='query']");

        //if (query.val().length > 0) {
        $.ajax({
            type: "GET",
            data: form.serialize(),
            url: form.attr('action'),
            success: function (data) {
                var count = form.find("input[name='count']");
                var offset = parseInt(form.find("input[name='offset']").val());
                var limit = parseInt(form.find("input[name='limit']").val());

                count.val(data.count);

                form.closest('div').find('.results').html(data.rendered);

                // Handle pagination
                form.closest('div').find('.pager li').addClass('pagination-disabled');
                if (offset > 0)
                    form.closest('div').find('.pager li.newer').removeClass('pagination-disabled');
                if (offset + limit <= data.count) {
                    form.closest('div').find('.pager li.older').removeClass('pagination-disabled');
                }
            }
        });



        //}
    }

    executeSearch(form_actual); // Load initial

    // Init sort buttons
    form.find('div.sort li').each(function () {
        var control = form.find("input[name='sort']");

        if ($(this).find('a').attr('data-sort') == control.attr('value')) {
            $('div.sort button').html($(this).find('a').html() + ' <span class="caret">');
        }
    });

    form.find('div.order li').each(function () {
        var control = form.find("input[name='order']");

        if ($(this).find('a').attr('data-sort') == control.attr('value')) {
            $('div.order button').html($(this).find('a').html() + ' <span class="caret">');
        }
    });

    // Activate search buttons
    form.find('div.sort li a').click(function (e) {
        e.preventDefault();

        var control = form.find("input[name='sort']");

        control.val($(this).attr('data-sort'));
        $('div.sort button').html($(this).html() + ' <span class="caret">');

        executeSearch(form_actual);
    });

    form.find('div.order li a').click(function (e) {
        e.preventDefault();

        var control = form.find("input[name='order']");

        control.val($(this).attr('data-sort'));
        $('div.order button').html($(this).html() + ' <span class="caret">');

        executeSearch(form_actual);
    });

    // Pagination
    $('.pager a').click(function (e) {
        e.preventDefault();

        var offset = parseInt(form.find("input[name='offset']").val());
        var limit = parseInt(form.find("input[name='limit']").val());
        var count = parseInt(form.find("input[name='count']").val());

        if ($(this).attr('rel') == 'prev') {
            if (offset > 0)
                form.find("input[name='offset']").val(offset - limit);
        } else {
            if (offset + limit <= count) {

                form.find("input[name='offset']").val(offset + limit);
            }
        }

        executeSearch(form_actual);
    });


    query.change(function () {
        form_actual.submit();
    });

    form_actual.submit(function(e) { 
        e.preventDefault();
        executeSearch(form_actual);
    });

</script>
<?php echo $this->draw('entity/edit/delete') ?>
<?php echo $this->draw('js/mentions') ?>
<script>
    /* Handle submit button */
    $(document).ready(function() {
        $('div.page-body form').submit(function() {
            
            var richrequiredcomplete = true;
            
            $(this).find('textarea.validation-required').each(function(){
                if ($(this).val().length == 0) {
                    richrequiredcomplete = false;
                }
            });
            
            
            // Fudge rich text completion for now.
            if (richrequiredcomplete == true) {
                    $('input[type=submit]').attr('disabled', 'yes').hide(); //val('Saving...').removeClass('btn-primary').addClass('btn-link');
                //$('input[type=submit]').attr('disabled', 'yes').val('Saving...').removeClass('btn-primary').addClass('btn-link');
                $('.btn-cancel').hide();
                $('#submit-spinner').show();
            }
        });
    });
</script>
<?php echo $this->__(['id' => 'submit-spinner'])->draw('entity/edit/spinner');

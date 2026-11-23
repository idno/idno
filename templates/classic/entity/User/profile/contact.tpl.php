<?php
if (!empty($vars['user']->profile['url']) && is_array($vars['user']->profile['url'])) {
    foreach($vars['user']->profile['url'] as $url) {
        if (!empty($url)) {

            $h_card = 'u-url';
            $url_display = $url;

            $display = true;
            $scheme = parse_url($url, PHP_URL_SCHEME);
            switch ($scheme) {
                case 'mailto' : $icon = 'fa fa-envelope-o'; $url_display = str_replace('mailto:', '', $url_display); $h_card = 'u-email';
                    break;
                case 'sms' : $icon = 'fa fa-mobile'; $url_display = str_replace('sms:', '', $url_display); $h_card = 'p-tel';
                    break;
                case 'tel' : $icon = 'fa fa-phone'; $url_display = str_replace('tel:', '', $url_display); $h_card = 'p-tel';
                    break;
                case 'facetime' : $icon = 'fa fa-video-camera'; $url_display = str_replace('facetime:', '', $url_display); $h_card = 'p-facetime';
                    break;
                default: $display = false;
            }
            if ($scheme != 'http' && $scheme != 'https') {
                $display = true;
            }

            if ($display) {
                ?>
                        <a href="<?php echo htmlspecialchars($url)?>" rel="me" class="<?php echo $h_card; ?> btn"><i class="<?php echo $icon?>"></i></a>
                <?php
            }
        }
    }
}

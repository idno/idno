<?php

namespace IdnoPlugins\Video {

    class ContentType extends \Idno\Common\ContentType
    {

        public $title = 'Video';
        public $category_title = 'Videos';
        public $entity_class = 'IdnoPlugins\\Video\\Video';

        // Micropub's "video" is an uploaded file, which the Media plugin takes
        public $indieWebContentType = array();

        function getDescription()
        {
            return 'Share a video link';
        }

        function getIconName()
        {
            return 'video';
        }

    }

}

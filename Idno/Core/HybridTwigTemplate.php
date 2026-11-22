<?php

/**
 * The template class that once rendered Twig files. Templates are drawn by Idno\Core\DefaultTemplate; the old
 * name is kept for one release, for code that extends it.
 */

namespace Idno\Core;

class_alias(DefaultTemplate::class, HybridTwigTemplate::class);

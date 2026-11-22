<div style="max-width:24rem;margin:2rem auto">
    <div class="idno-editor" style="text-align:center">
        <h4 class="idno-editor-heading">
            <?= \Idno\Core\Idno::site()->language()->_('Welcome back!') ?>
        </h4>

        <form action="<?= \Idno\Core\Idno::site()->config()->getDisplayURL() ?>session/login" method="post">
            <div class="idno-form-field">
                <input type="text" name="email" autofocus
                       class="idno-input" style="text-align:left"
                       placeholder="<?= \Idno\Core\Idno::site()->language()->_('Your email address or username') ?>">
            </div>
            <div class="idno-form-field">
                <input type="password" name="password"
                       class="idno-input" style="text-align:left"
                       placeholder="<?= \Idno\Core\Idno::site()->language()->_('Password') ?>">
            </div>

            <?= $this->__(['action' => '/session/login'])->draw('forms/input/captcha') ?>

            <div class="idno-form-field">
                <button type="submit" class="idno-btn idno-btn-primary" style="width:100%">
                    <?= \Idno\Core\Idno::site()->language()->_('Sign in') ?>
                </button>
                <input type="hidden" name="fwd" value="<?php
                    if (!empty($vars['fwd'])) {
                        echo htmlspecialchars($vars['fwd']);
                    } else if (!empty($_SERVER['HTTP_REFERER'])) {
                        echo htmlspecialchars($_SERVER['HTTP_REFERER']);
                    } else {
                        echo \Idno\Core\Idno::site()->config()->getDisplayURL();
                    }
                ?>">
            </div>

            <?= \Idno\Core\Idno::site()->actions()->signForm('/session/login') ?>
        </form>

        <div style="margin-top:1rem;font-size:var(--font-size-sm);color:var(--color-text-muted)">
            <a href="<?= \Idno\Core\Idno::site()->config()->getDisplayURL() ?>account/password" style="color:inherit">
                <?= \Idno\Core\Idno::site()->language()->_('Forgot your password?') ?>
            </a>
            <?php if (\Idno\Core\Idno::site()->config()->open_registration == true && \Idno\Core\Idno::site()->config()->canAddUsers()) { ?>
                <span style="margin:0 0.25rem">&middot;</span>
                <a href="<?= \Idno\Core\Idno::site()->config()->getDisplayURL() ?>account/register" style="color:inherit">
                    <?= \Idno\Core\Idno::site()->language()->_('Register') ?>
                </a>
            <?php } ?>
        </div>
    </div>
</div>

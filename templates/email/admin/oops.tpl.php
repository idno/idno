<div style="font-weight: bold; font-size: 30px; line-height: 32px; color: #333" align="center">
    An error occured on your Idno site <?php echo htmlspecialchars($vars['site']); ?>!
</div><br>
<hr/>
<br>
<pre>
    <?php echo htmlspecialchars($vars['message']); ?>
</pre>
<br><br>
Logged in user was <?php echo htmlspecialchars($vars['user']); ?> <br>

Agent: <?php echo htmlspecialchars($vars['agent']); ?> <br>

QS: <?php echo htmlspecialchars($vars['qs']); ?> <br>

Referrer: <?php echo htmlspecialchars($vars['referrer']); ?> <br>
<br>
<br>
<br>

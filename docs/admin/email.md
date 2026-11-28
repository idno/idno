# Email

This page shows you how to connect your site to a mail service, so that invitations, password resets and other
emails reach people's inboxes, and how to find out what went wrong when they don't.

## Why your site needs a mail service

Your site sends email when you invite someone, when a member forgets their password, and for a few other things
(listed [below](#what-your-site-sends)). It hands each email to a **mail server** over SMTP, the standard way programs
send mail.

Most web servers can't deliver mail well by themselves: mail from them is often refused or lands in spam. The
reliable way is to use a **mail service**, a company that delivers email for you, such as SendGrid, Postmark, Mailgun,
Amazon SES, Brevo or Resend. You create an account with one, and enter the details it gives you on your site's email
settings page.

Until you do, the Users page and the password reset pages warn that emails will probably not arrive. If no mail
server is set, Idno still tries to send through a mail server on the web server itself.

## The email settings page

Open `/admin/email/` ("Email" in the menu). The top of the page says where mail is sent through now, or that no mail
service is set up yet.

### Who the mail is from

**From address**
: Every email is sent from this address. It must be one your mail service lets you send from: usually an address on
  your own domain that you have set up with the service. You must fill this in once you enter a mail server.

**From name**
: The name people see beside the address. Leave it empty to use your site's name.

**Reply-To address (optional)**
: Where replies go, if not to the From address: for example your own inbox.

### Mail server

**Server (SMTP host)**
: The name of the mail server, exactly as your mail service gives it, such as `smtp.sendgrid.net`. Enter the name
  only: no `https://` and no port.

**Security**
: How the connection to the mail server is protected. Pick the one your mail service asks for:

  | Choice | Usual port | What it means |
  |---|---|---|
  | **STARTTLS (usual, port 587)** | 587 | The connection starts plain and switches to encryption straight away. This is what most mail services expect. |
  | **TLS (port 465)** | 465 | The connection is encrypted from the very start. |
  | **None (port 25)** | 25 | No encryption. Only for a mail server on your own network. |

**Port**
: Leave it empty to use the usual port for the security you chose. Port 587 goes with STARTTLS and 465 with TLS; mixing
  them up is the most common reason a connection fails.

**User name**
: The SMTP user name your mail service gives you. For some services it is a fixed word, such as `apikey` for SendGrid.

**Password**
: The SMTP password or API key. Once saved, it is never shown again, not even to you. Leave the field empty to keep
  the saved one, or type a new one to replace it. Tick **Remove the saved password** to delete it. Emptying the
  **User name** removes the password too.

Press **Save settings**. If something is wrong, nothing is saved, and the page marks the fields to fix.

### Using a mail service

Open **Using a mail service**, under the form, for the values to enter for SendGrid, Postmark, Mailgun, Amazon SES,
Brevo and Resend, with what each one needs before it will send. A button for each service fills in its server,
STARTTLS on port 587 and, where the service uses a fixed word, its user name. You still type the password yourself.

The same section explains how to keep your mail out of spam folders. In short: your mail service will ask you to add a
few DNS records where your domain is managed (usually DKIM and SPF records, which it gives you). Add a DMARC record as
well: a TXT record named `_dmarc` holding `v=DMARC1; p=none` is enough to start. Mail from a free address, such as a
Gmail address, is likely to be refused or marked as spam.

### Settings from config.ini

You can also put the mail settings in your site's `config.ini` file (or set them in the environment). If you do,
those values win over the ones saved on this page, and the page lists which settings come from there. Change those in
`config.ini`. The names are `from_email`, `from_name`, `reply_to_email`, `smtp_host`, `smtp_port`, `smtp_secure`
(`tls` for STARTTLS, `ssl` for TLS, or empty), `smtp_username` and `smtp_password`.

One more setting lives only in `config.ini`: `smtp_timeout`, how many seconds to wait for the mail server before
giving up. It is 10 unless you set it.

## Sending a test email

Under **Send a test email**, check the address in **Send it to** (it starts as yours) and press **Send test email**.
The test uses the settings as they are saved, so save your changes first.

If the mail server takes the email, the page says so. Check that it arrives, and look in your spam folder too. If it
doesn't, the page says why in plain words. The usual reasons are:

| What the page says | What it means | What to try |
|---|---|---|
| The mail server did not accept the user name and password. | The service refused to let your site sign in. | Check both. Many services want a fixed user name and an API key as the password. |
| The mail server ... could not be found. | The server name doesn't exist. | Check the spelling of the server name. |
| The mail server refused the connection, or did not answer. | Nothing answered on that port, or something in between blocked it. | Check the server and port, and that the security matches the port. Some web hosts block outgoing mail ports: ask yours. |
| A secure connection to the mail server could not be made. | The security setting doesn't match what the server offers on that port. | 587 goes with STARTTLS, 465 with TLS. |
| The mail server's security certificate could not be verified. | The name you entered isn't the name on the server's certificate. | Use the server name exactly as your mail service gives it. |
| The mail server would not send from this address. | Your From address isn't one the service lets you send from. | Verify the address or its domain with your mail service. |
| The mail server refused the message. | The service rejected this email. | Read what the server said, which the page quotes. |
| The mail server could not take the message just now. | A temporary problem at the service. | Try again in a few minutes. |

The page never shows your user name or password in these messages.

## What your site sends

- **Invitations**, when an administrator invites people from the Users page.
- **Password resets**, when a member asks for one.
- **The test email** from this page.
- **Import results**, to the administrator who started an import from the Import page.
- **Moderation emails**, when the Moderation plugin is enabled: to members who report something, to members whose
  posts are taken down or who are banned, and to administrators about new reports. See [Moderation](moderation.md).
- **Error reports**, to the addresses in the `oops_notify` setting of `config.ini`, if you set one.

Every email names your site and links to it using the address in your site's configuration. Emails carry no images
and nothing that tells anyone when they were opened.

## Where next

- Invite people now that mail works: [Members and invitations](users.md#inviting-people).
- All the `config.ini` settings: [the configuration reference](../install/config.md).
- Back to [Administering an Idno site](index.md).

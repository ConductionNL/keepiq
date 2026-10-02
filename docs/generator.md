# Generating passwords and passphrases

Keepiq makes strong passwords and passphrases for you. They are made in your browser, so the server never sees them. The server only ever stores the encrypted value.

## Generating a password

Create or edit a secret and choose the dice button next to the value. The generator opens on **Password**.

- **Length** sets the number of characters, from 8 to 128.
- **Include special characters** adds symbols such as `!`, `#` and `%`.
- **Exclude characters** leaves out characters you list, for example `0Ol1I` for characters that look alike.

Choose **Generate**, then **Use** to put the value in the secret. Use the copy button to copy it first.

Under **Advanced** you can give a pattern instead, such as `[A-Z0-9]{20}`. The pattern needs a length, like `{20}` or `{12,16}`. It also needs a set of characters in square brackets.

## Generating a passphrase

Choose **Passphrase** in the generator. A passphrase is a row of random words, such as `velvet-hamper-oxidize-trophy-crispy`. It is easier to type and to remember than a password of the same strength.

- **Number of words** sets 4 to 12 words. Five is the default.
- **Separator** goes between the words. A hyphen is the default; a space works too.
- **Capitalise each word** starts every word with a capital.
- **Include a number** adds one digit to one word.

The words come from a list of 7,776 words. The Electronic Frontier Foundation (EFF) published it for passphrases. Each word adds about 13 bits of randomness, so five words give about 64 bits.

## When your organisation sets a password policy

Your administrator can set a minimum length and the kinds of characters a generated value must contain. The generator follows the policy by itself:

- A password gets at least the minimum length and every required kind of character.
- A passphrase gets extra words until it is long enough. It gets capitals, a digit or a symbol between the words when the policy asks for them.
- A pattern that cannot meet the policy is refused, with the reason.

## For administrators

Set the policy under **Administration settings, Keepiq, Org password policy**. Turn off **Allow passphrases made of words** to offer only passwords. The generator then shows no Passphrase choice.

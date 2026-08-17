<?php

declare(strict_types=1);

namespace Makeview\Readme;

use Makeview\CredentialKeys;
use Makeview\Value\Credential;

/**
 * Credentials passed as arguments to a command.
 *
 * READMEs document access by pasting the command that works — `psql -U app`,
 * `curl -u admin:pass`, `ssh deploy@host` — far more often than they write a
 * `user:` label above it. There is no introducing word for the vocabulary scan
 * to find, only a flag, so these went entirely unread.
 *
 * Flags are matched by name rather than by command, because the same letters
 * mean the same thing across the tools READMEs actually quote: `-u` is a user
 * for `mysql` and `curl` alike. The exception is `-p`, which is a password for
 * `mysql` and a *port* for `psql`, so it is only read when the value is
 * attached to the flag — the form `mysql` requires for a password and `psql`
 * never uses for a port.
 */
final class CommandLineDetector
{
    /** Flags naming a username, in both their short and long spellings. */
    private const USER_FLAGS = ['-u', '-U', '--user', '--username', '--user-name'];

    /**
     * Flags naming a password. `-p` is deliberately absent: it means `--port`
     * to `psql` and `--password` to `mysql`, and only the attached form
     * (`-pSecret`) is unambiguous. See {@see attachedPasswordIn()}.
     */
    private const PASSWORD_FLAGS = ['--password', '--pass', '--passwd'];

    /** Flags naming a token or API key. */
    private const TOKEN_FLAGS = ['--token', '--api-key', '--apikey', '--access-token', '--secret'];

    /**
     * Flags whose value is never a credential. Listed explicitly so that a
     * `-p 5432` port or a `-h db.internal` host cannot be mistaken for one.
     */
    private const IGNORED_FLAGS = ['-h', '--host', '-P', '--port', '-d', '--database', '-D'];

    /**
     * @param Block[] $blocks
     * @return list<array{credential: Credential, heading: string}>
     */
    public static function detect(array $blocks): array
    {
        $found = [];

        foreach ($blocks as $block) {
            // Fenced blocks are where commands are usually pasted; an indented
            // one comes back as a paragraph, and READMEs use that form just as
            // often. A paragraph line only qualifies if it actually looks like
            // a command — see commandLike() — so ordinary prose mentioning a
            // dash does not reach the flag scan.
            if ($block->type !== 'fence' && $block->type !== 'paragraph') {
                continue;
            }

            foreach (preg_split('/\R/', $block->text) ?: [] as $line) {
                foreach (self::inCommand($line) as $credential) {
                    $found[] = ['credential' => $credential, 'heading' => $block->heading];
                }
            }
        }

        return $found;
    }

    /** Commands whose arguments are worth scanning when found in running text. */
    private const COMMANDS = [
        'psql', 'mysql', 'mysqldump', 'mongo', 'mongosh', 'redis-cli', 'mongodump',
        'curl', 'wget', 'http', 'ssh', 'scp', 'sftp', 'rsync', 'ftp',
        'docker', 'kubectl', 'helm', 'aws', 'gcloud', 'az', 'git', 'npm', 'pip',
    ];

    /**
     * Whether a line of prose is actually a command. An indented command block
     * is indistinguishable from a paragraph once BlockParser is done with it,
     * so the first word has to carry the evidence: a sentence mentioning a
     * password must not be run through a flag parser that would read its
     * hyphens as flags.
     */
    private static function commandLike(string $line): bool
    {
        $trimmed = ltrim($line);

        // A shell prompt or an `export` is unambiguous on its own.
        $trimmed = (string) preg_replace('/^[$#>]\s*/', '', $trimmed);
        $trimmed = (string) preg_replace('/^(?:sudo|env)\s+/', '', $trimmed);

        $first = strtok($trimmed, " \t");
        if ($first === false) {
            return false;
        }

        return in_array(basename($first), self::COMMANDS, true);
    }

    /** @return list<Credential> */
    public static function inCommand(string $line): array
    {
        $tokens = preg_split('/\s+/', trim($line), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        // Only an actual command is parsed for flags. A fence holds pasted
        // output and `Email: admin@test.com` lines as readily as it holds
        // commands, and reading those as arguments turned an e-mail address
        // into an `ssh user@host` target, reporting the local part alone.
        if ($tokens === [] || !self::commandLike($line)) {
            return [];
        }

        $found = [];

        foreach ($tokens as $index => $token) {
            foreach (self::fromToken($tokens, $index, $token) as $credential) {
                $found[] = $credential;
            }
        }

        return $found;
    }

    /**
     * @param list<string> $tokens
     * @return list<Credential>
     */
    private static function fromToken(array $tokens, int $index, string $token): array
    {
        [$flag, $attached] = self::splitFlag($token);

        if (in_array($flag, self::IGNORED_FLAGS, true)) {
            return [];
        }

        // `-u admin:hunter2` (curl) states both halves in one argument.
        if (in_array($flag, self::USER_FLAGS, true)) {
            $value = $attached ?? self::argumentAfter($tokens, $index);

            return $value === null ? [] : self::userAndOptionalSecret($value);
        }

        if (in_array($flag, self::PASSWORD_FLAGS, true)) {
            $value = $attached ?? self::argumentAfter($tokens, $index);

            return self::credential('password', 'heslo', $value);
        }

        if (in_array($flag, self::TOKEN_FLAGS, true)) {
            $value = $attached ?? self::argumentAfter($tokens, $index);

            return self::credential('token', 'token', $value);
        }

        $mysqlPassword = self::attachedPasswordIn($token);
        if ($mysqlPassword !== null) {
            return self::credential('password', 'heslo', $mysqlPassword);
        }

        return self::sshTargetIn($token);
    }

    /**
     * `--user=admin` carries its value in the same argument; `--user admin`
     * does not. Returns the flag and the attached value, if any.
     *
     * @return array{0: string, 1: ?string}
     */
    private static function splitFlag(string $token): array
    {
        if (!str_starts_with($token, '-')) {
            return [$token, null];
        }

        $position = strpos($token, '=');
        if ($position === false) {
            return [$token, null];
        }

        $value = substr($token, $position + 1);

        return [substr($token, 0, $position), $value === '' ? null : $value];
    }

    /**
     * The next argument, when it is a value rather than another flag. A flag
     * followed immediately by another flag took no argument: `mysql -u root -p`
     * prompts for the password rather than stating one.
     *
     * @param list<string> $tokens
     */
    private static function argumentAfter(array $tokens, int $index): ?string
    {
        $next = $tokens[$index + 1] ?? null;

        if ($next === null || str_starts_with($next, '-')) {
            return null;
        }

        return $next;
    }

    /**
     * `mysql -pSecret` attaches the password directly to the flag, with no
     * separator. Only the attached form is read: a bare `-p` is the
     * interactive prompt, and treating the next argument as its value turns
     * `mysql -u root -p mydb` into a password of "mydb".
     */
    private static function attachedPasswordIn(string $token): ?string
    {
        if (preg_match('/^-p(.+)$/', $token, $m) !== 1) {
            return null;
        }

        // `-p 5432` never reaches here (it has no attached value), but a
        // numeric attachment is a port in every tool that spells it that way.
        return ctype_digit($m[1]) ? null : $m[1];
    }

    /**
     * `ssh deploy@app.example.com` and `scp file user@host:/path` name the
     * account to log in as. The host half is not reported: it is an address,
     * not a secret.
     *
     * @return list<Credential>
     */
    private static function sshTargetIn(string $token): array
    {
        if (preg_match('/^([A-Za-z][A-Za-z0-9._-]*)@([A-Za-z0-9.-]+)(?::.*)?$/', $token, $m) !== 1) {
            return [];
        }

        // An e-mail address has the same shape. A hostname with no dot is a
        // container or LAN name and still a login target; one with a dot is
        // only distinguishable from an e-mail address by context, so the
        // trailing path or the absence of a mail-like TLD is not enough. The
        // conservative reading is to take it: a README quoting `x@y` inside a
        // fenced command block is documenting a login, not correspondence.
        return self::credential('user', 'uživatel', $m[1]);
    }

    /**
     * `-u admin:hunter2` is curl's spelling of a full account. The colon form
     * is only split when both halves are non-empty; `-u admin:` is curl asking
     * to prompt for the password.
     *
     * @return list<Credential>
     */
    private static function userAndOptionalSecret(string $value): array
    {
        if (preg_match('/^([^:\s]+):(.+)$/', $value, $m) !== 1) {
            return self::credential('user', 'uživatel', $value);
        }

        return [
            ...self::credential('user', 'uživatel', $m[1]),
            ...self::credential('password', 'heslo', $m[2]),
        ];
    }

    /** @return list<Credential> */
    private static function credential(string $kind, string $label, ?string $value): array
    {
        if ($value === null) {
            return [];
        }

        $trimmed = trim($value, " \t\"'`");

        if ($trimmed === '' || CredentialKeys::isNoise($trimmed)) {
            return [];
        }

        // A flag names the argument as surely as a `heslo:` label does, and the
        // command's own grammar ties the value to it.
        $evidence = ['introduced' => true, 'structured' => true];

        return [new Credential(
            $kind,
            $label,
            $trimmed,
            CredentialKeys::isPlaceholder($trimmed),
            Scorer::score($trimmed, $evidence),
            Scorer::explain($trimmed, $evidence),
        )];
    }
}

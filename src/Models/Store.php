<?php
declare(strict_types=1);
namespace App\Models;

use PDO;

final class Store
{
    public readonly PDO $db;

    public function __construct(public readonly string $path)
    {
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true) && !is_dir(dirname($path))) {
            throw new \RuntimeException('Diretório indisponível');
        }
        $this->db = new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('PRAGMA busy_timeout=5000');
        $this->db->exec('PRAGMA journal_mode=WAL');
        $this->db->exec('CREATE TABLE IF NOT EXISTS conversations (id TEXT PRIMARY KEY, data TEXT NOT NULL);
            CREATE TABLE IF NOT EXISTS inbox (id INTEGER PRIMARY KEY AUTOINCREMENT, conversation TEXT NOT NULL, external_id TEXT NOT NULL, data TEXT NOT NULL, done INTEGER NOT NULL DEFAULT 0, UNIQUE(conversation,external_id));
            CREATE TABLE IF NOT EXISTS outbox (id INTEGER PRIMARY KEY AUTOINCREMENT, data TEXT NOT NULL, text TEXT NOT NULL, done INTEGER NOT NULL DEFAULT 0, attempts INTEGER NOT NULL DEFAULT 0, available INTEGER NOT NULL DEFAULT 0);
            CREATE TABLE IF NOT EXISTS tasks (id INTEGER PRIMARY KEY AUTOINCREMENT, conversation TEXT NOT NULL, kind TEXT NOT NULL, attempts INTEGER NOT NULL DEFAULT 0, available INTEGER NOT NULL, done INTEGER NOT NULL DEFAULT 0, UNIQUE(conversation,kind));
            CREATE TABLE IF NOT EXISTS human_cases (id INTEGER PRIMARY KEY AUTOINCREMENT, conversation TEXT NOT NULL, kind TEXT NOT NULL, status TEXT NOT NULL DEFAULT "open", details TEXT NOT NULL, created INTEGER NOT NULL, updated INTEGER NOT NULL, resolution TEXT, UNIQUE(conversation,kind));
            CREATE TABLE IF NOT EXISTS operator_events (id INTEGER PRIMARY KEY AUTOINCREMENT, case_id INTEGER NOT NULL, action TEXT NOT NULL, created INTEGER NOT NULL)');
        $columns = array_column($this->db->query('PRAGMA table_info(outbox)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('conversation', $columns, true)) {
            $this->db->exec("ALTER TABLE outbox ADD COLUMN conversation TEXT NOT NULL DEFAULT ''");
            $this->db->exec("UPDATE outbox SET conversation=json_extract(data,'$.key')");
        }
        $inboxColumns = array_column($this->db->query('PRAGMA table_info(inbox)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('received_at', $inboxColumns, true)) {
            $this->db->exec('ALTER TABLE inbox ADD COLUMN received_at INTEGER NOT NULL DEFAULT 0');
        }
        $this->db->exec('CREATE INDEX IF NOT EXISTS outbox_pending ON outbox(conversation,done,id);
            CREATE INDEX IF NOT EXISTS inbox_pending ON inbox(done,id);
            CREATE INDEX IF NOT EXISTS tasks_due ON tasks(done,available,id)');
    }

    public function transaction(callable $action): mixed
    {
        if ($this->db->inTransaction()) { return $action(); }
        $this->db->beginTransaction();
        try { $result = $action(); $this->db->commit(); return $result; }
        catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
    }

    public function enqueue(array $m): bool
    {
        $s = $this->db->prepare('INSERT OR IGNORE INTO inbox(conversation,external_id,data,received_at) VALUES(?,?,?,?)');
        $s->execute([$m['key'], $m['id'], json_encode($m, JSON_THROW_ON_ERROR), time()]);
        return $s->rowCount() === 1;
    }

    public function load(string $key): array
    {
        $s = $this->db->prepare('SELECT data FROM conversations WHERE id=?');
        $s->execute([$key]);
        $r = $s->fetchColumn();
        return $r === false ? ['state' => 'start', 'data' => []] : json_decode($r, true, 512, JSON_THROW_ON_ERROR);
    }

    public function save(string $key, array $c): void
    {
        $s = $this->db->prepare('INSERT INTO conversations(id,data) VALUES(?,?) ON CONFLICT(id) DO UPDATE SET data=excluded.data');
        $s->execute([$key, json_encode($c, JSON_THROW_ON_ERROR)]);
    }

    public function reply(array $route, string $text): void
    {
        $route = array_intersect_key($route, array_flip(['key','number','company','channel','buttons']));
        $s = $this->db->prepare('INSERT INTO outbox(conversation,data,text) VALUES(?,?,?)');
        $s->execute([$route['key'], json_encode($route, JSON_THROW_ON_ERROR), $text]);
    }

    public function finish(int|array $id, array $m, array $c, string $text): void
    {
        $this->transaction(function () use ($id, $m, $c, $text) {
            $this->save($m['key'], $c);
            if ($text !== '') { $this->reply($m + ['buttons'=>\App\Services\ConversationMessages::buttons($c)], $text); }
            $s = $this->db->prepare('UPDATE inbox SET done=1 WHERE id=?');
            foreach ((array)$id as $entryId) { $s->execute([$entryId]); }
        });
    }

    public function schedule(string $key, string $kind, int $available, bool $restart = false): void
    {
        $sql = 'INSERT INTO tasks(conversation,kind,available) VALUES(?,?,?) ON CONFLICT(conversation,kind) ';
        $sql .= $restart ? 'DO UPDATE SET attempts=0,done=0,available=excluded.available' : 'DO NOTHING';
        $this->db->prepare($sql)->execute([$key, $kind, $available]);
    }

    public function stopTasks(string $key): void
    {
        $this->db->prepare('UPDATE tasks SET done=1 WHERE conversation=?')->execute([$key]);
    }

    public function case(string $key, string $kind, array $details): int
    {
        $now = time();
        $s = $this->db->prepare('INSERT INTO human_cases(conversation,kind,details,created,updated) VALUES(?,?,?,?,?)
            ON CONFLICT(conversation,kind) DO UPDATE SET details=excluded.details,updated=excluded.updated,status="open",resolution=NULL');
        $s->execute([$key, $kind, json_encode($details, JSON_THROW_ON_ERROR), $now, $now]);
        $s = $this->db->prepare('SELECT id FROM human_cases WHERE conversation=? AND kind=?');
        $s->execute([$key, $kind]);
        return (int) $s->fetchColumn();
    }

    public function resolveKind(string $key, string $kind, string $resolution): void
    {
        $this->db->prepare('UPDATE human_cases SET status="resolved",resolution=?,updated=? WHERE conversation=? AND kind=?')
            ->execute([$resolution, time(), $key, $kind]);
    }

    public function nextOutput(int $now): ?array
    {
        // A saída anterior pendente OU morta bloqueia somente a própria conversa.
        $s = $this->db->prepare('SELECT o.* FROM outbox o WHERE o.done=0 AND o.available<=?
            AND NOT EXISTS (SELECT 1 FROM outbox earlier WHERE earlier.conversation=o.conversation
                AND earlier.id<o.id AND earlier.done IN (0,2))
            ORDER BY o.id LIMIT 1');
        $s->execute([$now]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

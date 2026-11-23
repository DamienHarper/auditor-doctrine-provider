<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\MigrationE2E\Fixtures;

use DH\Auditor\Tests\MigrationE2E\Fixtures\Entity\Author;
use DH\Auditor\Tests\MigrationE2E\Fixtures\Entity\Bike;
use DH\Auditor\Tests\MigrationE2E\Fixtures\Entity\Car;
use DH\Auditor\Tests\MigrationE2E\Fixtures\Entity\Comment;
use DH\Auditor\Tests\MigrationE2E\Fixtures\Entity\Country;
use DH\Auditor\Tests\MigrationE2E\Fixtures\Entity\Post;
use DH\Auditor\Tests\MigrationE2E\Fixtures\Entity\Status;
use DH\Auditor\Tests\MigrationE2E\Fixtures\Entity\Tag;
use DH\Auditor\Tests\MigrationE2E\Fixtures\Entity\Translation;
use DH\Auditor\Tests\MigrationE2E\Fixtures\Type\Money;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The changes audited before the migration. Plain ORM code, deterministic, independent of the provider version.
 *
 * Every kind of audit entry (insert, update, remove, associate, dissociate), every kind of value, several
 * authors (including anonymous ones), transactions over several tables and storages, and enough rows and
 * transactions to span several conversion batches.
 */
final class Scenario
{
    /** Rows of the volume table: more than twice the 500-row conversion batch. */
    public const int VOLUME = 1_100;

    public function __construct(private readonly EntityManagerInterface $em) {}

    public function run(): void
    {
        $this->createWithEveryKindOfValue();
        $this->createRelationsInOneTransaction();
        $this->updateAnonymously();
        $this->removeAndDissociate();
        $this->inheritance();
        $this->volume();
    }

    private function createWithEveryKindOfValue(): void
    {
        Blame::as(['1', 'dark.vador'], '10.0.0.1', 'main', ['request_id' => 'req-1', 'tags' => ['import', 'cli']]);

        $vador = new Author('Dark Vador');
        $vador->email = 'vador@example.com';
        $vador->password = 'never audited';
        $vador->bio = "Zoë ✓ \"quoted\" \\ backslash / slash\nnew line\ttab — emoji 🚀 — 中文 — ".str_repeat('long text ', 600);
        $vador->birthDate = new \DateTimeImmutable('1977-05-25');
        $vador->active = true;
        $vador->rating = '4.50';
        $vador->score = 1.0;
        $vador->followers = PHP_INT_MAX;
        $vador->settings = ['theme' => 'dark', 'notifications' => ['email' => true, 'sms' => false], 'empty' => [], 'list' => [1, 2, 3]];
        $vador->status = Status::Active;

        $leia = new Author('Leia Organa');
        $leia->score = 0.1;
        $leia->rating = '0.00';
        $leia->followers = 0;
        $leia->settings = [];

        $nobody = new Author('Nobody'); // mostly null values

        $this->em->persist($vador);
        $this->em->persist($leia);
        $this->em->persist($nobody);
        $this->em->persist(new Country('FR', 'France'));
        $this->em->persist(new Country('JP', '日本'));
        $this->em->persist(new Translation('fr', 'hello', 'Bonjour à tous'));
        $this->em->persist(new Translation('en', 'hello', 'Hello'));
        $this->em->flush();
    }

    private function createRelationsInOneTransaction(): void
    {
        Blame::as(['2', 'anakin.skywalker'], '2001:db8::1', 'api');

        $this->em->wrapInTransaction(function (EntityManagerInterface $em): void {
            $vador = $this->author('Dark Vador');
            $leia = $this->author('Leia Organa');

            $tags = [];
            foreach (['php', 'doctrine', 'audit', 'symfony'] as $title) {
                $tags[$title] = new Tag($title);
                $em->persist($tags[$title]);
            }

            $first = new Post('First post');
            $first->body = 'Body of the first post';
            $first->publishedAt = new \DateTimeImmutable('2024-01-02 03:04:05');
            $first->price = new Money('12.34', 'EUR');
            $first->author = $vador;
            $first->tags->add($tags['php']);
            $first->tags->add($tags['doctrine']);
            $first->tags->add($tags['audit']);

            $second = new Post('Second post');
            $second->author = $leia;
            $second->tags->add($tags['symfony']);
            $second->tags->add($tags['php']);

            $em->persist($first);
            $em->persist($second);
            $em->flush();

            // second flush of the same database transaction
            foreach (['Great post!', 'I disagree', 'Ünïcödé çømmënt 💬'] as $i => $body) {
                $comment = new Comment($body);
                $comment->post = 0 === $i % 2 ? $first : $second;
                $em->persist($comment);
            }

            $em->flush();
        });
    }

    private function updateAnonymously(): void
    {
        Blame::as(null);

        $vador = $this->author('Dark Vador');
        $vador->email = null;
        $vador->password = 'changed, still not audited';
        $vador->bio = 'Short bio';
        $vador->birthDate = new \DateTimeImmutable('1977-05-26');
        $vador->active = false;
        $vador->rating = '1.50';
        $vador->score = 1.5;
        $vador->followers = 42;
        $vador->status = Status::Banned;
        $vador->settings = ['theme' => 'light', 'notifications' => ['email' => false], 'added' => ['deep' => ['key' => 'value']], 'list' => [1, 3]];

        $leia = $this->author('Leia Organa');
        $leia->settings = ['first' => 'key'];
        $leia->score = null;

        $nobody = $this->author('Nobody');
        $nobody->fullname = 'Somebody';
        $nobody->settings = ['scalar']; // a JSON list

        $first = $this->post('First post');
        $first->title = 'First post (edited)';
        $first->price = new Money('99.00', 'USD');
        $first->author = $leia;
        $first->tags->removeElement($this->tag('audit')); // dissociate

        $second = $this->post('Second post');
        $second->tags->clear(); // dissociate every tag
        $second->price = null;

        $this->country('JP')->name = 'Japan';

        $this->em->flush();

        // anonymous web request: no user, but an IP address and a firewall
        Blame::as(null, '203.0.113.9', 'main');
        $this->country('JP')->name = 'Nippon';
        $this->em->flush();
    }

    private function removeAndDissociate(): void
    {
        Blame::as(['3', 'Zoë "Ω" ✓'], '192.168.0.1', null, ['reason' => 'cleanup', 'ticket' => 42]);

        $first = $this->post('First post (edited)');
        foreach ($this->em->getRepository(Comment::class)->findBy(['post' => $first]) as $comment) {
            $this->em->remove($comment);
        }

        $this->em->remove($first); // removes a post that still has tags: dissociate + remove
        $this->em->remove($this->country('FR'));
        $this->em->remove($this->author('Somebody'));
        $this->em->flush();
    }

    private function inheritance(): void
    {
        Blame::as(['1', 'dark.vador'], '10.0.0.1', 'main');

        $car = new Car('Speeder');
        $car->doors = 0;
        $bike = new Bike('Speeder bike');
        $bike->gears = 3;
        $this->em->persist($car);
        $this->em->persist($bike);
        $this->em->flush();

        $car->doors = 2;
        $bike->label = 'Swoop';
        $this->em->flush();

        $this->em->remove($bike);
        $this->em->flush();
    }

    /**
     * One transaction per translation, then updates in larger flushes: more rows than two conversion batches,
     * and more transactions than one transaction_hash conversion batch.
     */
    private function volume(): void
    {
        Blame::as(['4', 'batch'], null, null, ['job' => 'import']);

        for ($i = 0; $i < self::VOLUME; ++$i) {
            $this->em->persist(new Translation('de', 'key.'.$i, 'Nachricht '.$i));
            if (0 === $i % 2) {
                $this->em->flush();
            }
        }

        $this->em->flush();
        $this->em->clear();

        Blame::as(['4', 'batch']);
        $translations = $this->em->getRepository(Translation::class)->findBy(['locale' => 'de'], ['key' => 'ASC'], 300);
        foreach ($translations as $i => $translation) {
            $translation->message .= ' (geändert)';
            if (0 === $i % 50) {
                $this->em->flush();
            }
        }

        $this->em->flush();
        $this->em->clear();
    }

    private function author(string $fullname): Author
    {
        return $this->em->getRepository(Author::class)->findOneBy(['fullname' => $fullname]) ?? throw new \LogicException($fullname);
    }

    private function post(string $title): Post
    {
        return $this->em->getRepository(Post::class)->findOneBy(['title' => $title]) ?? throw new \LogicException($title);
    }

    private function tag(string $title): Tag
    {
        return $this->em->getRepository(Tag::class)->findOneBy(['title' => $title]) ?? throw new \LogicException($title);
    }

    private function country(string $isoCode): Country
    {
        return $this->em->find(Country::class, $isoCode) ?? throw new \LogicException($isoCode);
    }
}

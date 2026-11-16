<?php

namespace Omnibridge\Eloquent\Tests\Fixtures\Repository;

use Base\Database\Repository\Finder\Result;
use Base\Database\Repository\ServiceEntityRepository;
use Omnibridge\Eloquent\Tests\Fixtures\Entity\Thread;

/**
 * An application's repository as the ensemble writes it: one class, agnostic, built from the persistence's Finders;
 * every query written once (the language, and sql() for an order on an expression).
 *
 * @extends ServiceEntityRepository<object>
 */
class ThreadRepository extends ServiceEntityRepository
{
    /** @return list<string> the titles published, oldest first */
    public function publishedTitles(): array
    {
        $published = $this->findByPublishedAtNotNull([], ['publishedAt' => 'ASC']);
        \assert($published instanceof Result);

        return array_map(static fn (mixed $thread): string => $thread instanceof Thread ? $thread->getTitle() : get_debug_type($thread), $published->getResult());
    }



    /**
     * ONE SQL body on both engines (an order on an expression): the longest titles first.
     *
     * @return list<string>
     */
    public function longestTitles(int $limit): array
    {
        $rows = $this->sql('SELECT t.{Thread.title} AS title FROM {Thread} t ORDER BY LENGTH(t.{Thread.title}) DESC, t.{Thread.id} ASC LIMIT '.$limit);
        \assert(\is_array($rows));

        return array_map(static fn (mixed $title): string => \is_scalar($title) ? (string) $title : '', array_column($rows, 'title'));
    }

    /**
     * ONE SQL body over a joined hierarchy: {Page} is the root's table and the page's own joined as one, {Page.position}
     * a column of the page's table.
     *
     * @return list<string>
     */
    public function pagesByPosition(): array
    {
        $rows = $this->sql('SELECT p.{Page.title} AS title FROM {Page} p WHERE p.{Page.position} > ? ORDER BY p.{Page.position} DESC', [0]);
        \assert(\is_array($rows));

        return array_map(static fn (mixed $title): string => \is_scalar($title) ? (string) $title : '', array_column($rows, 'title'));
    }

    /**
     * An entity as a parameter of sql(): bound by its identifier, though it prints as a string.
     *
     * @return list<string>
     */
    public function titlesOf(object $author): array
    {
        $rows = $this->sql('SELECT t.{Thread.title} AS title FROM {Thread} t WHERE t.{Thread.author} = ? ORDER BY t.{Thread.id} ASC', [$author]);
        \assert(\is_array($rows));

        return array_map(static fn (mixed $title): string => \is_scalar($title) ? (string) $title : '', array_column($rows, 'title'));
    }
}

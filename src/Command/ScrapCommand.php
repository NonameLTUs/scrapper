<?php

namespace App\Command;

use App\Entity\Article;
use Doctrine\ORM\EntityManagerInterface;
use Goutte\Client;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DomCrawler\Crawler;

class ScrapCommand extends Command
{
    /**
     * @var EntityManagerInterface
     */
    private $em;

    private $contentDetected = 0;

    private $url = "https://truckmanualshub.com/category/trucks";

    protected static $defaultName = 'app:scrap';

    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;

        parent::__construct();
    }

    protected function configure()
    {
        //
    }

    private function getCrawlerByUrl($url)
    {
        $client = new Client();

        return $client->request('GET', $url);
    }

    private function getArticleImageUrl(Crawler $node)
    {
        $image = $node->filter('img');
        if (0 === $image->count()) {
            return "";
        }

        $url        = $image->first()->attr('src');
        $extension  = array_reverse(explode('.', $url))[0];
        $removeSize = array_reverse(explode('-', $url));
        unset($removeSize[0]);
        $removeSize  = array_reverse($removeSize);
        $implodedUrl = implode('-', $removeSize);

        return "{$implodedUrl}.{$extension}";
    }

    private function getArticleDescription(Crawler $crawler)
    {
        $description           = null;
        $this->contentDetected = 0;

        $result = $crawler->filter('.entry-content > *')->each(function (Crawler $node) use ($crawler) {
            if ("h2" === $node->nodeName() || "table" === $node->nodeName()) {
                $this->contentDetected = true;
            }

            if (
                $node->attr('quads-location') ||
                ("div" === $node->nodeName() && false === strpos($node->attr('id'), 'attachment_'))
            ) {
                return null;
            }

            if ($this->contentDetected) {
                return "<{$node->nodeName()}>{$node->html()}</{$node->nodeName()}>";
            }

            if (("div" === $node->nodeName() || "p" === $node->nodeName()) && 0 < $node->children()->count()) {
                if ("img" === $node->children()->first()->nodeName()) {
                    $this->contentDetected = true;
                }
            }

            if (
                "img" === $node->nodeName() ||
                false !== strpos($node->attr('id'), 'attachment_')
            ) {
                $this->contentDetected = true;
            }

            return null;
        });

        return implode("", $result);
    }

    private function crawl(Crawler $crawler, OutputInterface $output)
    {
        $crawler->filter('article')->each(function (Crawler $articleNode) use ($output) {
            $heading = $articleNode->filter('.entry-header > .entry-title > a')->first();
            $url     = $heading->attr('href');
            $title   = $heading->text();

            $output->writeLn("[CRAWLING] {$title}");

            $brand          = $articleNode->filter('.categories > a')->first()->text();
            $articleCrawler = $this->getCrawlerByUrl($url);
            $imageUrl       = $this->getArticleImageUrl($articleNode);
            $description    = $this->getArticleDescription($articleCrawler);

            $output->writeln($imageUrl);
            $article = (new Article())
                ->setTitle($title)
                ->setDescription($description)
                ->setBrand($brand)
                ->setImageUrl($imageUrl);
            $this->em->persist($article);
        });

        $this->em->flush();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $crawler = $this->getCrawlerByUrl($this->url);

        $output->writeLn("[CRAWLING PAGE] 1");
        $this->crawl($crawler, $output);

        $pagination = $crawler->filter('.pagination > ul > li');
        if (0 < $pagination->count()) {
            $pages = $pagination->last()->text();

            for ($i = 2; $i <= $pages; $i++) {
                $output->writeLn("[CRAWLING PAGE] {$i}");
                $crawler = $this->getCrawlerByUrl("{$this->url}/page/{$i}");
                $this->crawl($crawler, $output);
            }
        }

        $output->writeLn('DONE');

        return 0;
    }
}
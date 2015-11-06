<?php

namespace DependencyTracker;

use DependencyTracker\Event\AstFileAnalyzedEvent;
use DependencyTracker\Event\AstFileSyntaxErrorEvent;
use DependencyTracker\Event\PostCreateAstMapEvent;
use DependencyTracker\Event\PreCreateAstMapEvent;
use DependencyTracker\Tests\Visitor\Fixtures\MultipleInteritanceB;
use PhpParser\Node\Name;
use PhpParser\NodeVisitor\NameResolver;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

class AstMapGenerator
{
    protected $dispatcher;

    public function __construct(
        EventDispatcherInterface $dispatcher
    )
    {
        $this->dispatcher = $dispatcher;
    }

    /**
     * @param Configuration $configuration
     * @param OutputInterface $output
     * @return AstMap|mixed
     */
    public function generateAstMap(Configuration $configuration, OutputInterface $output)
    {
        $files = $this->collectFiles($configuration);

        $cacheFile = sys_get_temp_dir().'/astmap.cache.'.$this->calculateCacheKey($files);

        $this->dispatcher->dispatch(PreCreateAstMapEvent::class, new PreCreateAstMapEvent(count($files)));

        #if (file_exists($cacheFile)) {
        #    $output->writeln("reading cachefile <info>".$cacheFile."</info>");
        #    $astMap = unserialize(file_get_contents($cacheFile));
        #} else {
            $output->writeln("writing cachefile <info>".$cacheFile."</info>");
            $this->createAstMapByFiles($astMap = new AstMap(), $files);
            file_put_contents($cacheFile, serialize($astMap));
        #}

        $this->dispatcher->dispatch(PostCreateAstMapEvent::class, new PostCreateAstMapEvent($astMap));

        return $astMap;
    }

    private function calculateCacheKey(array $files)
    {
        return $cacheKey = sha1(array_reduce(
            array_map(function(\SplFileInfo $fileInfo) {
                return md5_file($fileInfo->getPathname());
            }, $files),
            function($a, $b) { return $a + $b; }
        ));
    }

    private function collectFiles(Configuration $configuration)
    {
        $files = iterator_to_array(
            (new Finder)
                ->in($configuration->getPaths())
                ->name('*.php')
                ->files()
                ->followLinks()
                ->ignoreUnreadableDirs(true)
                ->ignoreVCS(true)
        );

        return array_filter($files, function(\SplFileInfo $fileInfo) use ($configuration) {
            foreach ($configuration->getExcludeFiles() as $excludeFiles) {
                if(preg_match('/'.$excludeFiles.'/i', $fileInfo->getPathname())) {
                    return false;
                }
            }

            return true;
        });
    }

    /**
     * @param AstMap $astMap
     * @param SplFileInfo[] $files
     */
    public function createAstMapByFiles(AstMap $astMap, array $files)
    {
        $parser = new \PhpParser\Parser(new \PhpParser\Lexer\Emulative);
        $traverser = new \PhpParser\NodeTraverser;
        $traverser->addVisitor(new NameResolver());

        gc_disable();

        foreach ($files as $file) {

            try {
                $code = file_get_contents($file->getPathname());
                $astMap->add($file->getPathname(), $ast = $traverser->traverse($parser->parse($code)));

                // add basic inheritance informations for every class.
                foreach (AstHelper::findClassLikeNodes($ast) as $classLikeNodes) {
                    $astMap->setClassInherit(
                        $classLikeNodes->namespacedName->toString(),
                        AstHelper::findInheritances($classLikeNodes)
                    );
                }

                $this->dispatcher->dispatch(
                    AstFileAnalyzedEvent::class,
                    new AstFileAnalyzedEvent(
                        $file, $ast
                    )
                );

            } catch (\PhpParser\Error $e) {
                $this->dispatcher->dispatch(
                    AstFileSyntaxErrorEvent::class,
                    new AstFileSyntaxErrorEvent(
                        $file, $e->getMessage()
                    )
                );
            }
        }

        gc_enable();

        $this->flattenInheritanceDependencies($astMap);
    }

    private function flattenInheritanceDependencies(AstMap $astMap)
    {

        foreach ($astMap->getAllInherits() as $klass => $inherits) {

            $inerhitInerhits = [];

            foreach ($inherits as $inherit) {
                $inerhitInerhits =  array_merge($inerhitInerhits, $this->resolveDepsRecursive($inherit, $astMap));
            }


            $astMap->setFlattenClassInherit(
                $klass,
                array_values(array_unique(array_filter($inerhitInerhits, function($v) use ($astMap, $klass) {
                    return !in_array($v, $astMap->getClassInherits($klass));
                })))
            );
        }
    }

    private function resolveDepsRecursive($class, AstMap $astMap, \ArrayObject $alreadyResolved = null)
    {
        if ($alreadyResolved == null) {
            $alreadyResolved = new \ArrayObject();
        }

        // recursion detected
        if (isset($alreadyResolved[$class])) {
            return [];
        }

        $alreadyResolved[$class] = true;

        $buffer = [];
        foreach ($astMap->getClassInherits($class) as $dep) {
            $buffer = array_merge($buffer, $this->resolveDepsRecursive($dep, $astMap, $alreadyResolved));
            $buffer[] = $dep;
        }

        return array_values(array_unique($buffer));
    }


}
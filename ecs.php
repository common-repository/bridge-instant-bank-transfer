<?php
use PhpCsFixer\Fixer\ArrayNotation\ArraySyntaxFixer;
use Symplify\EasyCodingStandard\ValueObject\Option;
use Symplify\EasyCodingStandard\ValueObject\Set\SetList;
use \Symplify\EasyCodingStandard\Config\ECSConfig;

return static function (ECSConfig $containerConfigurator): void {
    $parameters = $containerConfigurator->parameters();

	$parameters->set(Option::PARALLEL, true);

	$services = $containerConfigurator->services();
	$services->set(ArraySyntaxFixer::class)
		->call('configure', [[
			'syntax' => 'short',
		]]);

	// run and fix, one by one
	$parameters->set(Option::INDENTATION, 'tab');
	$containerConfigurator->import(SetList::ARRAY);
	$containerConfigurator->import(SetList::DOCBLOCK);
	$containerConfigurator->import(SetList::COMMENTS);
	$containerConfigurator->import(SetList::CONTROL_STRUCTURES);
    
    // A. full sets
    $containerConfigurator->import(SetList::PSR_12);
};

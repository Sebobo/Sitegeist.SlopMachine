<?php

declare(strict_types=1);

namespace Sitegeist\SlopMachine\Domain;

use Neos\ContentRepository\Domain\Model\NodeType;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Validation\Validator\ValidatorInterface;

/**
 * The property validators of a node type, both read and enforced.
 *
 * A node type declares what a property value may look like in its validation section, and
 * neither the content repository nor the write path ever looked at it: the repository casts
 * a value to the declared property type and stops there, so a metaDescription beyond the
 * configured maximum was stored without complaint and only surfaced later, when a human tried
 * to edit the node in the Neos UI and the editor refused to save it.
 *
 * The Neos UI validates against exactly this configuration before it writes a property
 * (@see \Neos\Neos\Ui\Domain\Model\Changes\Property::canApply()), so this class mirrors that
 * behaviour instead of inventing a second set of rules: same configuration, same resolution of
 * validator names, same handling of the validationErrorMessage option.
 *
 * The same object serves both sides on purpose. What the schema reports under `constraints` is
 * derived from the options of the validators that are actually run, so an agent reading the
 * schema and a write being rejected are talking about the same limits.
 */
#[Flow\Proxy(false)]
class NodePropertyValidation
{
    /**
     * Node type definitions name validators the way the Neos UI does, with the package
     * "Neos.Neos" standing in for the Flow validators: 'Neos.Neos/Validation/StringLengthValidator'
     * is \Neos\Flow\Validation\Validator\StringLengthValidator.
     */
    private const UI_PACKAGE = 'Neos.Neos';
    private const FLOW_VALIDATOR_NAMESPACE = 'Neos\\Flow\\Validation\\Validator\\';

    /**
     * Validator options that translate into a normalized constraint an agent can act on
     * without knowing the validator: option name => constraint name.
     */
    private const CONSTRAINT_OPTIONS = [
        'StringLengthValidator' => ['minimum' => 'minLength', 'maximum' => 'maxLength'],
        'CountValidator' => ['minimum' => 'minItems', 'maximum' => 'maxItems'],
        'NumberRangeValidator' => ['minimum' => 'minimum', 'maximum' => 'maximum'],
        'FileSizeValidator' => ['minimum' => 'minFileSize', 'maximum' => 'maxFileSize'],
        'RegularExpressionValidator' => ['regularExpression' => 'pattern'],
        'FileExtensionValidator' => ['allowedExtensions' => 'fileExtensions'],
        'MediaTypeValidator' => ['allowedTypes' => 'allowedMediaTypes'],
        'DateTimeRangeValidator' => ['earliestDate' => 'earliestDate', 'latestDate' => 'latestDate'],
    ];

    /**
     * Validators that describe a format rather than a range or a pattern.
     */
    private const CONSTRAINT_FORMATS = [
        'EmailAddressValidator' => 'email',
        'AlphanumericValidator' => 'alphanumeric',
        'UuidValidator' => 'uuid',
    ];

    /**
     * The full validation of one property: the normalized constraints and the raw validators.
     *
     * @param array<string,mixed> $propertyConfiguration
     * @return array{constraints: array<string,mixed>, validators: list<array<string,mixed>>}
     */
    public function describe(array $propertyConfiguration): array
    {
        $validators = [];
        $constraints = [];
        foreach ($this->validatorConfigurations($propertyConfiguration) as $validatorName => $options) {
            $validators[] = [
                'name' => $validatorName,
                'options' => $options,
                // A validator that cannot be resolved is reported instead of silently
                // dropping out: a constraint the server cannot run is not a constraint.
                'enforced' => $this->resolveValidator($validatorName, $options) !== null,
            ];
            $constraints += $this->constraintsOf($this->shortValidatorName($validatorName), $options);
        }

        return [
            'constraints' => $constraints,
            'validators' => $validators,
        ];
    }

    /**
     * The normalized constraints alone, for the reduced schema.
     *
     * @param array<string,mixed> $propertyConfiguration
     * @return array<string,mixed>
     */
    public function constraints(array $propertyConfiguration): array
    {
        return $this->describe($propertyConfiguration)['constraints'];
    }

    /**
     * Every violation of the given property values, all of them at once so that a caller can
     * correct a whole command in one go instead of one property per round trip.
     *
     * Only the properties that are part of the write are checked. A node that already carries a
     * stale violation somewhere else stays editable; only the value that is written has to be
     * valid.
     *
     * @param array<string,mixed> $propertyValues
     * @return list<string>
     */
    public function violations(NodeType $nodeType, array $propertyValues): array
    {
        $propertyConfigurations = $nodeType->getProperties();
        $violations = [];
        foreach ($propertyValues as $propertyName => $value) {
            $propertyConfiguration = $propertyConfigurations[$propertyName] ?? null;
            if (!\is_array($propertyConfiguration)) {
                continue;
            }
            foreach ($this->validatorConfigurations($propertyConfiguration) as $validatorName => $options) {
                $validator = $this->resolveValidator($validatorName, $options);
                if ($validator === null) {
                    continue;
                }
                try {
                    $result = $validator->validate($value);
                } catch (\Throwable) {
                    // A validator that cannot run on this value (a broken regular expression,
                    // an unparseable date comparison) must not stand in for a violation.
                    continue;
                }
                if (!$result->hasErrors()) {
                    continue;
                }
                $violations[] = $this->describeViolation(
                    $propertyName,
                    $this->shortValidatorName($validatorName),
                    $options,
                    $value,
                    $result->getErrors(),
                );
            }
        }

        return $violations;
    }

    /**
     * @param array<string,mixed> $propertyConfiguration
     * @return array<string,array<string,mixed>>
     */
    private function validatorConfigurations(array $propertyConfiguration): array
    {
        $validation = $propertyConfiguration['validation'] ?? null;
        if (!\is_array($validation)) {
            return [];
        }

        $configurations = [];
        foreach ($validation as $validatorName => $validatorConfiguration) {
            if (!\is_string($validatorName)) {
                continue;
            }
            $options = \is_array($validatorConfiguration) ? $validatorConfiguration : [];
            // The Neos UI omits this option because no validator supports it; a validator
            // receiving it throws instead of validating (neos-ui issue 3691).
            unset($options['validationErrorMessage']);
            $configurations[$validatorName] = $options;
        }

        return $configurations;
    }

    /**
     * @param array<string,mixed> $options
     */
    private function resolveValidator(string $validatorName, array $options): ?ValidatorInterface
    {
        // The configured form is 'Neos.Neos/Validation/StringLengthValidator'.
        $nameParts = \explode('/', $validatorName);
        if (\count($nameParts) !== 3 || $nameParts[0] !== self::UI_PACKAGE || $nameParts[1] !== 'Validation') {
            return null;
        }

        $className = self::FLOW_VALIDATOR_NAMESPACE . $nameParts[2];
        if (!\class_exists($className) || !\is_a($className, ValidatorInterface::class, true)) {
            return null;
        }

        return new $className($options);
    }

    private function shortValidatorName(string $validatorName): string
    {
        $nameParts = \explode('/', $validatorName);

        return \end($nameParts);
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function constraintsOf(string $shortValidatorName, array $options): array
    {
        if (isset(self::CONSTRAINT_FORMATS[$shortValidatorName])) {
            return ['format' => self::CONSTRAINT_FORMATS[$shortValidatorName]];
        }

        $constraints = [];
        foreach (self::CONSTRAINT_OPTIONS[$shortValidatorName] ?? [] as $optionName => $constraintName) {
            if (!\array_key_exists($optionName, $options)) {
                continue;
            }
            $constraints[$constraintName] = $options[$optionName];
        }

        return $constraints;
    }

    /**
     * @param array<string,mixed> $options
     * @param iterable<\Neos\Error\Messages\Error> $errors
     */
    private function describeViolation(
        string $propertyName,
        string $shortValidatorName,
        array $options,
        mixed $value,
        iterable $errors,
    ): string {
        $messages = [];
        foreach ($errors as $error) {
            // render() interpolates the arguments of the message template; getMessage() would
            // hand out "This text may not exceed %1$d characters." with the limit missing.
            $messages[] = $error->render();
        }
        $message = \trim(\implode(' ', \array_filter($messages)));
        if ($message === '') {
            $message = \sprintf('the value does not satisfy %s.', $shortValidatorName);
        }

        // The length is the one number a caller has to act on and the validators phrase it
        // relative to the limit ("may not exceed 230 characters"), so the actual length is
        // added here rather than guessed by the caller.
        if (\is_string($value) && $shortValidatorName === 'StringLengthValidator') {
            $message .= \sprintf(' The value is %d characters long.', \mb_strlen($value));
        }

        return \sprintf('%s: %s', $propertyName, $message);
    }
}

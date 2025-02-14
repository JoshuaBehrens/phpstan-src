<?php declare(strict_types = 1);

namespace PHPStan\Analyser;

use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\BinaryOp\BooleanAnd;
use PhpParser\Node\Expr\BinaryOp\BooleanOr;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\BooleanNot;
use PhpParser\Node\Expr\Cast;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\ErrorSuppress;
use PhpParser\Node\Expr\Exit_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Instanceof_;
use PhpParser\Node\Expr\List_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Break_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Continue_;
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\Echo_;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Stmt\Static_;
use PhpParser\Node\Stmt\StaticVar;
use PhpParser\Node\Stmt\Switch_;
use PhpParser\Node\Stmt\Throw_;
use PhpParser\Node\Stmt\TryCatch;
use PhpParser\Node\Stmt\Unset_;
use PhpParser\Node\Stmt\While_;
use PHPStan\BetterReflection\Reflection\Adapter\ReflectionClass;
use PHPStan\BetterReflection\Reflector\ClassReflector;
use PHPStan\BetterReflection\SourceLocator\Ast\Strategy\NodeToReflection;
use PHPStan\BetterReflection\SourceLocator\Located\LocatedSource;
use PHPStan\DependencyInjection\Reflection\ClassReflectionExtensionRegistryProvider;
use PHPStan\File\FileHelper;
use PHPStan\File\FileReader;
use PHPStan\Node\BooleanAndNode;
use PHPStan\Node\BooleanOrNode;
use PHPStan\Node\ClassConstantsNode;
use PHPStan\Node\ClassMethodsNode;
use PHPStan\Node\ClassPropertiesNode;
use PHPStan\Node\ClassPropertyNode;
use PHPStan\Node\ClassStatementsGatherer;
use PHPStan\Node\ClosureReturnStatementsNode;
use PHPStan\Node\ExecutionEndNode;
use PHPStan\Node\FunctionReturnStatementsNode;
use PHPStan\Node\InArrowFunctionNode;
use PHPStan\Node\InClassMethodNode;
use PHPStan\Node\InClassNode;
use PHPStan\Node\InClosureNode;
use PHPStan\Node\InFunctionNode;
use PHPStan\Node\LiteralArrayItem;
use PHPStan\Node\LiteralArrayNode;
use PHPStan\Node\MatchExpressionArm;
use PHPStan\Node\MatchExpressionArmCondition;
use PHPStan\Node\MatchExpressionNode;
use PHPStan\Node\MethodReturnStatementsNode;
use PHPStan\Node\ReturnStatement;
use PHPStan\Node\UnreachableStatementNode;
use PHPStan\Parser\Parser;
use PHPStan\Php\PhpVersion;
use PHPStan\PhpDoc\PhpDocInheritanceResolver;
use PHPStan\PhpDoc\ResolvedPhpDocBlock;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\Native\NativeMethodReflection;
use PHPStan\Reflection\ParametersAcceptor;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Reflection\PassedByReference;
use PHPStan\Reflection\Php\DummyParameter;
use PHPStan\Reflection\Php\PhpMethodReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\TrinaryLogic;
use PHPStan\Type\Accessory\NonEmptyArrayType;
use PHPStan\Type\ArrayType;
use PHPStan\Type\BooleanType;
use PHPStan\Type\CallableType;
use PHPStan\Type\ClosureType;
use PHPStan\Type\Constant\ConstantArrayType;
use PHPStan\Type\Constant\ConstantArrayTypeBuilder;
use PHPStan\Type\Constant\ConstantBooleanType;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\ErrorType;
use PHPStan\Type\FileTypeMapper;
use PHPStan\Type\Generic\TemplateTypeHelper;
use PHPStan\Type\Generic\TemplateTypeMap;
use PHPStan\Type\IntegerType;
use PHPStan\Type\MixedType;
use PHPStan\Type\NeverType;
use PHPStan\Type\NullType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StaticType;
use PHPStan\Type\StaticTypeFactory;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\TypeTraverser;
use PHPStan\Type\TypeUtils;
use PHPStan\Type\UnionType;

class NodeScopeResolver
{

	private const LOOP_SCOPE_ITERATIONS = 3;
	private const GENERALIZE_AFTER_ITERATION = 1;

	private \PHPStan\Reflection\ReflectionProvider $reflectionProvider;

	private ClassReflector $classReflector;

	private ClassReflectionExtensionRegistryProvider $classReflectionExtensionRegistryProvider;

	private \PHPStan\Parser\Parser $parser;

	private \PHPStan\Type\FileTypeMapper $fileTypeMapper;

	private PhpVersion $phpVersion;

	private \PHPStan\PhpDoc\PhpDocInheritanceResolver $phpDocInheritanceResolver;

	private \PHPStan\File\FileHelper $fileHelper;

	private \PHPStan\Analyser\TypeSpecifier $typeSpecifier;

	private bool $polluteScopeWithLoopInitialAssignments;

	private bool $polluteCatchScopeWithTryAssignments;

	private bool $polluteScopeWithAlwaysIterableForeach;

	/** @var string[][] className(string) => methods(string[]) */
	private array $earlyTerminatingMethodCalls;

	/** @var array<int, string> */
	private array $earlyTerminatingFunctionCalls;

	/** @var bool[] filePath(string) => bool(true) */
	private array $analysedFiles = [];

	/**
	 * @param \PHPStan\Reflection\ReflectionProvider $reflectionProvider
	 * @param ClassReflector $classReflector
	 * @param Parser $parser
	 * @param FileTypeMapper $fileTypeMapper
	 * @param PhpDocInheritanceResolver $phpDocInheritanceResolver
	 * @param FileHelper $fileHelper
	 * @param TypeSpecifier $typeSpecifier
	 * @param bool $polluteScopeWithLoopInitialAssignments
	 * @param bool $polluteCatchScopeWithTryAssignments
	 * @param bool $polluteScopeWithAlwaysIterableForeach
	 * @param string[][] $earlyTerminatingMethodCalls className(string) => methods(string[])
	 * @param array<int, string> $earlyTerminatingFunctionCalls
	 */
	public function __construct(
		ReflectionProvider $reflectionProvider,
		ClassReflector $classReflector,
		ClassReflectionExtensionRegistryProvider $classReflectionExtensionRegistryProvider,
		Parser $parser,
		FileTypeMapper $fileTypeMapper,
		PhpVersion $phpVersion,
		PhpDocInheritanceResolver $phpDocInheritanceResolver,
		FileHelper $fileHelper,
		TypeSpecifier $typeSpecifier,
		bool $polluteScopeWithLoopInitialAssignments,
		bool $polluteCatchScopeWithTryAssignments,
		bool $polluteScopeWithAlwaysIterableForeach,
		array $earlyTerminatingMethodCalls,
		array $earlyTerminatingFunctionCalls
	)
	{
		$this->reflectionProvider = $reflectionProvider;
		$this->classReflector = $classReflector;
		$this->classReflectionExtensionRegistryProvider = $classReflectionExtensionRegistryProvider;
		$this->parser = $parser;
		$this->fileTypeMapper = $fileTypeMapper;
		$this->phpVersion = $phpVersion;
		$this->phpDocInheritanceResolver = $phpDocInheritanceResolver;
		$this->fileHelper = $fileHelper;
		$this->typeSpecifier = $typeSpecifier;
		$this->polluteScopeWithLoopInitialAssignments = $polluteScopeWithLoopInitialAssignments;
		$this->polluteCatchScopeWithTryAssignments = $polluteCatchScopeWithTryAssignments;
		$this->polluteScopeWithAlwaysIterableForeach = $polluteScopeWithAlwaysIterableForeach;
		$this->earlyTerminatingMethodCalls = $earlyTerminatingMethodCalls;
		$this->earlyTerminatingFunctionCalls = $earlyTerminatingFunctionCalls;
	}

	/**
	 * @param string[] $files
	 */
	public function setAnalysedFiles(array $files): void
	{
		$this->analysedFiles = array_fill_keys($files, true);
	}

	/**
	 * @param \PhpParser\Node[] $nodes
	 * @param \PHPStan\Analyser\MutatingScope $scope
	 * @param callable(\PhpParser\Node $node, Scope $scope): void $nodeCallback
	 */
	public function processNodes(
		array $nodes,
		MutatingScope $scope,
		callable $nodeCallback
	): void
	{
		$nodesCount = count($nodes);
		foreach ($nodes as $i => $node) {
			if (!$node instanceof Node\Stmt) {
				continue;
			}

			$statementResult = $this->processStmtNode($node, $scope, $nodeCallback);
			$scope = $statementResult->getScope();
			if (!$statementResult->isAlwaysTerminating()) {
				continue;
			}

			if ($i < $nodesCount - 1) {
				$nextStmt = $nodes[$i + 1];
				if (!$nextStmt instanceof Node\Stmt) {
					continue;
				}

				$nodeCallback(new UnreachableStatementNode($nextStmt), $scope);
			}
			break;
		}
	}

	/**
	 * @param \PhpParser\Node $parentNode
	 * @param \PhpParser\Node\Stmt[] $stmts
	 * @param \PHPStan\Analyser\MutatingScope $scope
	 * @param callable(\PhpParser\Node $node, Scope $scope): void $nodeCallback
	 * @return StatementResult
	 */
	public function processStmtNodes(
		Node $parentNode,
		array $stmts,
		MutatingScope $scope,
		callable $nodeCallback
	): StatementResult
	{
		$exitPoints = [];
		$alreadyTerminated = false;
		$hasYield = false;
		$stmtCount = count($stmts);
		$shouldCheckLastStatement = $parentNode instanceof Node\Stmt\Function_
			|| $parentNode instanceof Node\Stmt\ClassMethod
			|| $parentNode instanceof Expr\Closure;
		foreach ($stmts as $i => $stmt) {
			$isLast = $i === $stmtCount - 1;
			$statementResult = $this->processStmtNode(
				$stmt,
				$scope,
				$nodeCallback
			);
			$scope = $statementResult->getScope();
			$hasYield = $hasYield || $statementResult->hasYield();

			if ($shouldCheckLastStatement && $isLast) {
				/** @var Node\Stmt\Function_|Node\Stmt\ClassMethod|Expr\Closure $parentNode */
				$parentNode = $parentNode;
				$nodeCallback(new ExecutionEndNode(
					$stmt,
					new StatementResult(
						$scope,
						$hasYield,
						$statementResult->isAlwaysTerminating(),
						$statementResult->getExitPoints()
					),
					$parentNode->returnType !== null
				), $scope);
			}

			$exitPoints = array_merge($exitPoints, $statementResult->getExitPoints());

			if (!$statementResult->isAlwaysTerminating()) {
				continue;
			}

			$alreadyTerminated = true;
			if ($i < $stmtCount - 1) {
				$nextStmt = $stmts[$i + 1];
				$nodeCallback(new UnreachableStatementNode($nextStmt), $scope);
			}
			break;
		}

		$statementResult = new StatementResult($scope, $hasYield, $alreadyTerminated, $exitPoints);
		if ($stmtCount === 0 && $shouldCheckLastStatement) {
			/** @var Node\Stmt\Function_|Node\Stmt\ClassMethod|Expr\Closure $parentNode */
			$parentNode = $parentNode;
			$nodeCallback(new ExecutionEndNode(
				$parentNode,
				$statementResult,
				$parentNode->returnType !== null
			), $scope);
		}

		return $statementResult;
	}

	/**
	 * @param \PhpParser\Node\Stmt $stmt
	 * @param \PHPStan\Analyser\MutatingScope $scope
	 * @param callable(\PhpParser\Node $node, Scope $scope): void $nodeCallback
	 * @return StatementResult
	 */
	private function processStmtNode(
		Node\Stmt $stmt,
		MutatingScope $scope,
		callable $nodeCallback
	): StatementResult
	{
		if (
			$stmt instanceof Throw_
			|| $stmt instanceof Return_
		) {
			$scope = $this->processStmtVarAnnotation($scope, $stmt, $stmt->expr);
		} elseif (
			!$stmt instanceof Static_
			&& !$stmt instanceof Foreach_
			&& !$stmt instanceof Node\Stmt\Global_
		) {
			$scope = $this->processStmtVarAnnotation($scope, $stmt, null);
		}

		if ($stmt instanceof Node\Stmt\ClassMethod) {
			if (!$scope->isInClass()) {
				throw new \PHPStan\ShouldNotHappenException();
			}
			if (
				$scope->isInTrait()
				&& $scope->getClassReflection()->hasNativeMethod($stmt->name->toString())
			) {
				$methodReflection = $scope->getClassReflection()->getNativeMethod($stmt->name->toString());
				if ($methodReflection instanceof NativeMethodReflection) {
					return new StatementResult($scope, false, false, []);
				}
				if ($methodReflection instanceof PhpMethodReflection) {
					$declaringTrait = $methodReflection->getDeclaringTrait();
					if ($declaringTrait === null || $declaringTrait->getName() !== $scope->getTraitReflection()->getName()) {
						return new StatementResult($scope, false, false, []);
					}
				}
			}
		}

		$nodeCallback($stmt, $scope);

		if ($stmt instanceof Node\Stmt\Declare_) {
			$hasYield = false;
			foreach ($stmt->declares as $declare) {
				$nodeCallback($declare, $scope);
				$nodeCallback($declare->value, $scope);
				if (
					$declare->key->name !== 'strict_types'
					|| !($declare->value instanceof Node\Scalar\LNumber)
					|| $declare->value->value !== 1
				) {
					continue;
				}

				$scope = $scope->enterDeclareStrictTypes();
			}
		} elseif ($stmt instanceof Node\Stmt\Function_) {
			$hasYield = false;
			foreach ($stmt->attrGroups as $attrGroup) {
				foreach ($attrGroup->attrs as $attr) {
					foreach ($attr->args as $arg) {
						$nodeCallback($arg->value, $scope);
					}
				}
			}
			[$templateTypeMap, $phpDocParameterTypes, $phpDocReturnType, $phpDocThrowType, $deprecatedDescription, $isDeprecated, $isInternal, $isFinal, $isPure] = $this->getPhpDocs($scope, $stmt);

			foreach ($stmt->params as $param) {
				$this->processParamNode($param, $scope, $nodeCallback);
			}

			if ($stmt->returnType !== null) {
				$nodeCallback($stmt->returnType, $scope);
			}

			$functionScope = $scope->enterFunction(
				$stmt,
				$templateTypeMap,
				$phpDocParameterTypes,
				$phpDocReturnType,
				$phpDocThrowType,
				$deprecatedDescription,
				$isDeprecated,
				$isInternal,
				$isFinal,
				$isPure
			);
			$nodeCallback(new InFunctionNode($stmt), $functionScope);

			$gatheredReturnStatements = [];
			$statementResult = $this->processStmtNodes($stmt, $stmt->stmts, $functionScope, static function (\PhpParser\Node $node, Scope $scope) use ($nodeCallback, $functionScope, &$gatheredReturnStatements): void {
				$nodeCallback($node, $scope);
				if ($scope->getFunction() !== $functionScope->getFunction()) {
					return;
				}
				if ($scope->isInAnonymousFunction()) {
					return;
				}
				if (!$node instanceof Return_) {
					return;
				}

				$gatheredReturnStatements[] = new ReturnStatement($scope, $node);
			});

			$nodeCallback(new FunctionReturnStatementsNode(
				$stmt,
				$gatheredReturnStatements,
				$statementResult
			), $functionScope);
		} elseif ($stmt instanceof Node\Stmt\ClassMethod) {
			$hasYield = false;
			foreach ($stmt->attrGroups as $attrGroup) {
				foreach ($attrGroup->attrs as $attr) {
					foreach ($attr->args as $arg) {
						$nodeCallback($arg->value, $scope);
					}
				}
			}
			[$templateTypeMap, $phpDocParameterTypes, $phpDocReturnType, $phpDocThrowType, $deprecatedDescription, $isDeprecated, $isInternal, $isFinal, $isPure] = $this->getPhpDocs($scope, $stmt);

			foreach ($stmt->params as $param) {
				$this->processParamNode($param, $scope, $nodeCallback);
			}

			if ($stmt->returnType !== null) {
				$nodeCallback($stmt->returnType, $scope);
			}

			if ($phpDocReturnType !== null) {
				if (!$scope->isInClass()) {
					throw new \PHPStan\ShouldNotHappenException();
				}

				$classReflection = $scope->getClassReflection();
				$phpDocReturnType = TypeTraverser::map($phpDocReturnType, static function (Type $type, callable $traverse) use ($classReflection): Type {
					if ($type instanceof StaticType) {
						$changedType = $type->changeBaseClass($classReflection);
						if ($classReflection->isFinal()) {
							$changedType = $changedType->getStaticObjectType();
						}
						return $traverse($changedType);
					}

					return $traverse($type);
				});
			}

			$methodScope = $scope->enterClassMethod(
				$stmt,
				$templateTypeMap,
				$phpDocParameterTypes,
				$phpDocReturnType,
				$phpDocThrowType,
				$deprecatedDescription,
				$isDeprecated,
				$isInternal,
				$isFinal,
				$isPure
			);

			if ($stmt->name->toLowerString() === '__construct') {
				foreach ($stmt->params as $param) {
					if ($param->flags === 0) {
						continue;
					}

					if (!$param->var instanceof Variable || !is_string($param->var->name)) {
						throw new \PHPStan\ShouldNotHappenException();
					}
					$phpDoc = null;
					if ($param->getDocComment() !== null) {
						$phpDoc = $param->getDocComment()->getText();
					}
					$nodeCallback(new ClassPropertyNode(
						$param->var->name,
						$param->flags,
						$param->type,
						$param->default,
						$phpDoc,
						true,
						$param
					), $methodScope);
				}
			}

			if ($stmt->getAttribute('virtual', false) === false) {
				$nodeCallback(new InClassMethodNode($stmt), $methodScope);
			}

			if ($stmt->stmts !== null) {
				$gatheredReturnStatements = [];
				$statementResult = $this->processStmtNodes($stmt, $stmt->stmts, $methodScope, static function (\PhpParser\Node $node, Scope $scope) use ($nodeCallback, $methodScope, &$gatheredReturnStatements): void {
					$nodeCallback($node, $scope);
					if ($scope->getFunction() !== $methodScope->getFunction()) {
						return;
					}
					if ($scope->isInAnonymousFunction()) {
						return;
					}
					if (!$node instanceof Return_) {
						return;
					}

					$gatheredReturnStatements[] = new ReturnStatement($scope, $node);
				});
				$nodeCallback(new MethodReturnStatementsNode(
					$stmt,
					$gatheredReturnStatements,
					$statementResult
				), $methodScope);
			}
		} elseif ($stmt instanceof Echo_) {
			$hasYield = false;
			foreach ($stmt->exprs as $echoExpr) {
				$result = $this->processExprNode($echoExpr, $scope, $nodeCallback, ExpressionContext::createDeep());
				$scope = $result->getScope();
				$hasYield = $hasYield || $result->hasYield();
			}
		} elseif ($stmt instanceof Return_) {
			if ($stmt->expr !== null) {
				$result = $this->processExprNode($stmt->expr, $scope, $nodeCallback, ExpressionContext::createDeep());
				$scope = $result->getScope();
				$hasYield = $result->hasYield();
			} else {
				$hasYield = false;
			}

			return new StatementResult($scope, $hasYield, true, [
				new StatementExitPoint($stmt, $scope),
			]);
		} elseif ($stmt instanceof Continue_ || $stmt instanceof Break_) {
			if ($stmt->num !== null) {
				$result = $this->processExprNode($stmt->num, $scope, $nodeCallback, ExpressionContext::createDeep());
				$scope = $result->getScope();
				$hasYield = $result->hasYield();
			} else {
				$hasYield = false;
			}

			return new StatementResult($scope, $hasYield, true, [
				new StatementExitPoint($stmt, $scope),
			]);
		} elseif ($stmt instanceof Node\Stmt\Expression) {
			$earlyTerminationExpr = $this->findEarlyTerminatingExpr($stmt->expr, $scope);
			$result = $this->processExprNode($stmt->expr, $scope, $nodeCallback, ExpressionContext::createTopLevel());
			$scope = $result->getScope();
			$scope = $scope->filterBySpecifiedTypes($this->typeSpecifier->specifyTypesInCondition(
				$scope,
				$stmt->expr,
				TypeSpecifierContext::createNull()
			));
			$hasYield = $result->hasYield();
			if ($earlyTerminationExpr !== null) {
				return new StatementResult($scope, $hasYield, true, [
					new StatementExitPoint($stmt, $scope),
				]);
			}
		} elseif ($stmt instanceof Node\Stmt\Namespace_) {
			if ($stmt->name !== null) {
				$scope = $scope->enterNamespace($stmt->name->toString());
			}

			$scope = $this->processStmtNodes($stmt, $stmt->stmts, $scope, $nodeCallback)->getScope();
			$hasYield = false;
		} elseif ($stmt instanceof Node\Stmt\Trait_) {
			return new StatementResult($scope, false, false, []);
		} elseif ($stmt instanceof Node\Stmt\ClassLike) {
			$hasYield = false;
			if (isset($stmt->namespacedName)) {
				$classReflection = $this->getCurrentClassReflection($stmt, $scope);
				$classScope = $scope->enterClass($classReflection);
				$nodeCallback(new InClassNode($stmt, $classReflection), $classScope);
			} elseif ($stmt instanceof Class_) {
				if ($stmt->name === null) {
					throw new \PHPStan\ShouldNotHappenException();
				}
				if ($stmt->getAttribute('anonymousClass', false) === false) {
					$classReflection = $this->reflectionProvider->getClass($stmt->name->toString());
				} else {
					$classReflection = $this->reflectionProvider->getAnonymousClassReflection($stmt, $scope);
				}
				$classScope = $scope->enterClass($classReflection);
				$nodeCallback(new InClassNode($stmt, $classReflection), $classScope);
			} else {
				throw new \PHPStan\ShouldNotHappenException();
			}

			foreach ($stmt->attrGroups as $attrGroup) {
				foreach ($attrGroup->attrs as $attr) {
					foreach ($attr->args as $arg) {
						$nodeCallback($arg->value, $classScope);
					}
				}
			}

			$classStatementsGatherer = new ClassStatementsGatherer($classReflection, $nodeCallback);
			$this->processStmtNodes($stmt, $stmt->stmts, $classScope, $classStatementsGatherer);
			$nodeCallback(new ClassPropertiesNode($stmt, $classStatementsGatherer->getProperties(), $classStatementsGatherer->getPropertyUsages(), $classStatementsGatherer->getMethodCalls()), $classScope);
			$nodeCallback(new ClassMethodsNode($stmt, $classStatementsGatherer->getMethods(), $classStatementsGatherer->getMethodCalls()), $classScope);
			$nodeCallback(new ClassConstantsNode($stmt, $classStatementsGatherer->getConstants(), $classStatementsGatherer->getConstantFetches()), $classScope);
		} elseif ($stmt instanceof Node\Stmt\Property) {
			$hasYield = false;
			foreach ($stmt->attrGroups as $attrGroup) {
				foreach ($attrGroup->attrs as $attr) {
					foreach ($attr->args as $arg) {
						$nodeCallback($arg->value, $scope);
					}
				}
			}
			foreach ($stmt->props as $prop) {
				$this->processStmtNode($prop, $scope, $nodeCallback);
				$docComment = $stmt->getDocComment();
				$nodeCallback(
					new ClassPropertyNode(
						$prop->name->toString(),
						$stmt->flags,
						$stmt->type,
						$prop->default,
						$docComment !== null ? $docComment->getText() : null,
						false,
						$prop
					),
					$scope
				);
			}

			if ($stmt->type !== null) {
				$nodeCallback($stmt->type, $scope);
			}
		} elseif ($stmt instanceof Node\Stmt\PropertyProperty) {
			$hasYield = false;
			if ($stmt->default !== null) {
				$this->processExprNode($stmt->default, $scope, $nodeCallback, ExpressionContext::createDeep());
			}
		} elseif ($stmt instanceof Throw_) {
			$result = $this->processExprNode($stmt->expr, $scope, $nodeCallback, ExpressionContext::createDeep());
			return new StatementResult($result->getScope(), $result->hasYield(), true, [
				new StatementExitPoint($stmt, $scope),
			]);
		} elseif ($stmt instanceof If_) {
			$conditionType = $scope->getType($stmt->cond)->toBoolean();
			$ifAlwaysTrue = $conditionType instanceof ConstantBooleanType && $conditionType->getValue();
			$condResult = $this->processExprNode($stmt->cond, $scope, $nodeCallback, ExpressionContext::createDeep());
			$exitPoints = [];
			$finalScope = null;
			$alwaysTerminating = true;
			$hasYield = $condResult->hasYield();

			$branchScopeStatementResult = $this->processStmtNodes($stmt, $stmt->stmts, $condResult->getTruthyScope(), $nodeCallback);

			if (!$conditionType instanceof ConstantBooleanType || $conditionType->getValue()) {
				$exitPoints = $branchScopeStatementResult->getExitPoints();
				$branchScope = $branchScopeStatementResult->getScope();
				$finalScope = $branchScopeStatementResult->isAlwaysTerminating() ? null : $branchScope;
				$alwaysTerminating = $branchScopeStatementResult->isAlwaysTerminating();
				$hasYield = $branchScopeStatementResult->hasYield() || $hasYield;
			}

			$scope = $condResult->getFalseyScope();
			$lastElseIfConditionIsTrue = false;

			$condScope = $scope;
			foreach ($stmt->elseifs as $elseif) {
				$nodeCallback($elseif, $scope);
				$elseIfConditionType = $condScope->getType($elseif->cond)->toBoolean();
				$condResult = $this->processExprNode($elseif->cond, $condScope, $nodeCallback, ExpressionContext::createDeep());
				$condScope = $condResult->getScope();
				$branchScopeStatementResult = $this->processStmtNodes($elseif, $elseif->stmts, $condResult->getTruthyScope(), $nodeCallback);

				if (
					!$ifAlwaysTrue
					&& (
						!$lastElseIfConditionIsTrue
						&& (
							!$elseIfConditionType instanceof ConstantBooleanType
							|| $elseIfConditionType->getValue()
						)
					)
				) {
					$exitPoints = array_merge($exitPoints, $branchScopeStatementResult->getExitPoints());
					$branchScope = $branchScopeStatementResult->getScope();
					$finalScope = $branchScopeStatementResult->isAlwaysTerminating() ? $finalScope : $branchScope->mergeWith($finalScope);
					$alwaysTerminating = $alwaysTerminating && $branchScopeStatementResult->isAlwaysTerminating();
					$hasYield = $hasYield || $branchScopeStatementResult->hasYield();
				}

				if (
					$elseIfConditionType instanceof ConstantBooleanType
					&& $elseIfConditionType->getValue()
				) {
					$lastElseIfConditionIsTrue = true;
				}

				$condScope = $condScope->filterByFalseyValue($elseif->cond);
				$scope = $condScope;
			}

			if ($stmt->else === null) {
				if (!$ifAlwaysTrue) {
					$finalScope = $scope->mergeWith($finalScope);
					$alwaysTerminating = false;
				}
			} else {
				$nodeCallback($stmt->else, $scope);
				$branchScopeStatementResult = $this->processStmtNodes($stmt->else, $stmt->else->stmts, $scope, $nodeCallback);

				if (!$ifAlwaysTrue && !$lastElseIfConditionIsTrue) {
					$exitPoints = array_merge($exitPoints, $branchScopeStatementResult->getExitPoints());
					$branchScope = $branchScopeStatementResult->getScope();
					$finalScope = $branchScopeStatementResult->isAlwaysTerminating() ? $finalScope : $branchScope->mergeWith($finalScope);
					$alwaysTerminating = $alwaysTerminating && $branchScopeStatementResult->isAlwaysTerminating();
					$hasYield = $hasYield || $branchScopeStatementResult->hasYield();
				}
			}

			if ($finalScope === null) {
				$finalScope = $scope;
			}

			return new StatementResult($finalScope, $hasYield, $alwaysTerminating, $exitPoints);
		} elseif ($stmt instanceof Node\Stmt\TraitUse) {
			$hasYield = false;
			$this->processTraitUse($stmt, $scope, $nodeCallback);
		} elseif ($stmt instanceof Foreach_) {
			$condResult = $this->processExprNode($stmt->expr, $scope, $nodeCallback, ExpressionContext::createDeep());
			$scope = $condResult->getScope();
			$arrayComparisonExpr = new BinaryOp\NotIdentical(
				$stmt->expr,
				new Array_([])
			);
			$bodyScope = $this->enterForeach($scope->filterByTruthyValue($arrayComparisonExpr), $stmt);
			$hasYield = false;
			$count = 0;
			do {
				$prevScope = $bodyScope;
				$bodyScope = $bodyScope->mergeWith($scope->filterByTruthyValue($arrayComparisonExpr));
				$bodyScope = $this->enterForeach($bodyScope, $stmt);
				$bodyScopeResult = $this->processStmtNodes($stmt, $stmt->stmts, $bodyScope, static function (): void {
				})->filterOutLoopExitPoints();
				$alwaysTerminating = $bodyScopeResult->isAlwaysTerminating();
				$bodyScope = $bodyScopeResult->getScope();
				foreach ($bodyScopeResult->getExitPointsByType(Continue_::class) as $continueExitPoint) {
					$bodyScope = $bodyScope->mergeWith($continueExitPoint->getScope());
				}
				if ($bodyScope->equals($prevScope)) {
					break;
				}

				if ($count >= self::GENERALIZE_AFTER_ITERATION) {
					$bodyScope = $bodyScope->generalizeWith($prevScope);
				}
				$count++;
			} while (!$alwaysTerminating && $count < self::LOOP_SCOPE_ITERATIONS);

			$bodyScope = $bodyScope->mergeWith($scope->filterByTruthyValue($arrayComparisonExpr));
			$bodyScope = $this->enterForeach($bodyScope, $stmt);
			$finalScopeResult = $this->processStmtNodes($stmt, $stmt->stmts, $bodyScope, $nodeCallback)->filterOutLoopExitPoints();
			$finalScope = $finalScopeResult->getScope();
			foreach ($finalScopeResult->getExitPointsByType(Continue_::class) as $continueExitPoint) {
				$finalScope = $continueExitPoint->getScope()->mergeWith($finalScope);
			}
			foreach ($finalScopeResult->getExitPointsByType(Break_::class) as $breakExitPoint) {
				$finalScope = $breakExitPoint->getScope()->mergeWith($finalScope);
			}

			$isIterableAtLeastOnce = $scope->getType($stmt->expr)->isIterableAtLeastOnce();
			if ($isIterableAtLeastOnce->no() || $finalScopeResult->isAlwaysTerminating()) {
				$finalScope = $scope;
			} elseif ($isIterableAtLeastOnce->maybe()) {
				if ($this->polluteScopeWithAlwaysIterableForeach) {
					$finalScope = $finalScope->mergeWith($scope->filterByFalseyValue($arrayComparisonExpr));
				} else {
					$finalScope = $finalScope->mergeWith($scope);
				}
			} elseif (!$this->polluteScopeWithAlwaysIterableForeach) {
				$finalScope = $scope->processAlwaysIterableForeachScopeWithoutPollute($finalScope);
				// get types from finalScope, but don't create new variables
			}

			return new StatementResult(
				$finalScope,
				$finalScopeResult->hasYield() || $condResult->hasYield(),
				$isIterableAtLeastOnce->yes() && $finalScopeResult->isAlwaysTerminating(),
				$finalScopeResult->getExitPointsForOuterLoop()
			);
		} elseif ($stmt instanceof While_) {
			$condResult = $this->processExprNode($stmt->cond, $scope, static function (): void {
			}, ExpressionContext::createDeep());
			$bodyScope = $condResult->getTruthyScope();
			$count = 0;
			do {
				$prevScope = $bodyScope;
				$bodyScope = $bodyScope->mergeWith($scope);
				$bodyScope = $this->processExprNode($stmt->cond, $bodyScope, static function (): void {
				}, ExpressionContext::createDeep())->getTruthyScope();
				$bodyScopeResult = $this->processStmtNodes($stmt, $stmt->stmts, $bodyScope, static function (): void {
				})->filterOutLoopExitPoints();
				$alwaysTerminating = $bodyScopeResult->isAlwaysTerminating();
				$bodyScope = $bodyScopeResult->getScope();
				foreach ($bodyScopeResult->getExitPointsByType(Continue_::class) as $continueExitPoint) {
					$bodyScope = $bodyScope->mergeWith($continueExitPoint->getScope());
				}
				if ($bodyScope->equals($prevScope)) {
					break;
				}

				if ($count >= self::GENERALIZE_AFTER_ITERATION) {
					$bodyScope = $bodyScope->generalizeWith($prevScope);
				}
				$count++;
			} while (!$alwaysTerminating && $count < self::LOOP_SCOPE_ITERATIONS);

			$bodyScope = $bodyScope->mergeWith($scope);
			$bodyScopeMaybeRan = $bodyScope;
			$bodyScope = $this->processExprNode($stmt->cond, $bodyScope, $nodeCallback, ExpressionContext::createDeep())->getTruthyScope();
			$finalScopeResult = $this->processStmtNodes($stmt, $stmt->stmts, $bodyScope, $nodeCallback)->filterOutLoopExitPoints();
			$finalScope = $finalScopeResult->getScope();
			foreach ($finalScopeResult->getExitPointsByType(Continue_::class) as $continueExitPoint) {
				$finalScope = $finalScope->mergeWith($continueExitPoint->getScope());
			}
			$breakExitPoints = $finalScopeResult->getExitPointsByType(Break_::class);
			foreach ($breakExitPoints as $breakExitPoint) {
				$finalScope = $finalScope->mergeWith($breakExitPoint->getScope());
			}

			$beforeCondBooleanType = $scope->getType($stmt->cond)->toBoolean();
			$condBooleanType = $bodyScopeMaybeRan->getType($stmt->cond)->toBoolean();
			$isIterableAtLeastOnce = $beforeCondBooleanType instanceof ConstantBooleanType && $beforeCondBooleanType->getValue();
			$alwaysIterates = $condBooleanType instanceof ConstantBooleanType && $condBooleanType->getValue();

			if ($alwaysIterates) {
				$isAlwaysTerminating = count($finalScopeResult->getExitPointsByType(Break_::class)) === 0;
			} elseif ($isIterableAtLeastOnce) {
				$isAlwaysTerminating = $finalScopeResult->isAlwaysTerminating();
			} else {
				$isAlwaysTerminating = false;
			}
			$condScope = $condResult->getFalseyScope();
			if (!$isIterableAtLeastOnce) {
				if (!$this->polluteScopeWithLoopInitialAssignments) {
					$condScope = $condScope->mergeWith($scope);
				}
				$finalScope = $finalScope->mergeWith($condScope);
			}

			return new StatementResult(
				$finalScope,
				$finalScopeResult->hasYield() || $condResult->hasYield(),
				$isAlwaysTerminating,
				$finalScopeResult->getExitPointsForOuterLoop()
			);
		} elseif ($stmt instanceof Do_) {
			$finalScope = null;
			$bodyScope = $scope;
			$count = 0;
			$hasYield = false;

			do {
				$prevScope = $bodyScope;
				$bodyScopeResult = $this->processStmtNodes($stmt, $stmt->stmts, $bodyScope, static function (): void {
				})->filterOutLoopExitPoints();
				$alwaysTerminating = $bodyScopeResult->isAlwaysTerminating();
				$bodyScope = $bodyScopeResult->getScope();
				foreach ($bodyScopeResult->getExitPointsByType(Continue_::class) as $continueExitPoint) {
					$bodyScope = $bodyScope->mergeWith($continueExitPoint->getScope());
				}
				$finalScope = $alwaysTerminating ? $finalScope : $bodyScope->mergeWith($finalScope);
				foreach ($bodyScopeResult->getExitPointsByType(Break_::class) as $breakExitPoint) {
					$finalScope = $breakExitPoint->getScope()->mergeWith($finalScope);
				}
				$bodyScope = $this->processExprNode($stmt->cond, $bodyScope, static function (): void {
				}, ExpressionContext::createDeep())->getTruthyScope();
				if ($bodyScope->equals($prevScope)) {
					break;
				}

				if ($count >= self::GENERALIZE_AFTER_ITERATION) {
					$bodyScope = $bodyScope->generalizeWith($prevScope);
				}
				$count++;
			} while (!$alwaysTerminating && $count < self::LOOP_SCOPE_ITERATIONS);

			$bodyScope = $bodyScope->mergeWith($scope);

			$bodyScopeResult = $this->processStmtNodes($stmt, $stmt->stmts, $bodyScope, $nodeCallback)->filterOutLoopExitPoints();
			$bodyScope = $bodyScopeResult->getScope();
			$condBooleanType = $bodyScope->getType($stmt->cond)->toBoolean();
			$alwaysIterates = $condBooleanType instanceof ConstantBooleanType && $condBooleanType->getValue();

			if ($alwaysIterates) {
				$alwaysTerminating = count($bodyScopeResult->getExitPointsByType(Break_::class)) === 0;
			} else {
				$alwaysTerminating = $bodyScopeResult->isAlwaysTerminating();
			}
			foreach ($bodyScopeResult->getExitPointsByType(Continue_::class) as $continueExitPoint) {
				$bodyScope = $bodyScope->mergeWith($continueExitPoint->getScope());
			}
			$finalScope = $alwaysTerminating ? $finalScope : $bodyScope->mergeWith($finalScope);
			if ($finalScope === null) {
				$finalScope = $scope;
			}
			if (!$alwaysTerminating) {
				$condResult = $this->processExprNode($stmt->cond, $bodyScope, $nodeCallback, ExpressionContext::createDeep());
				$hasYield = $condResult->hasYield();
				$finalScope = $condResult->getFalseyScope();
			}
			foreach ($bodyScopeResult->getExitPointsByType(Break_::class) as $breakExitPoint) {
				$finalScope = $breakExitPoint->getScope()->mergeWith($finalScope);
			}

			return new StatementResult(
				$finalScope,
				$bodyScopeResult->hasYield() || $hasYield,
				$alwaysTerminating,
				$bodyScopeResult->getExitPointsForOuterLoop()
			);
		} elseif ($stmt instanceof For_) {
			$initScope = $scope;
			$hasYield = false;
			foreach ($stmt->init as $initExpr) {
				$initScope = $this->processExprNode($initExpr, $initScope, $nodeCallback, ExpressionContext::createTopLevel())->getScope();
			}

			$bodyScope = $initScope;
			foreach ($stmt->cond as $condExpr) {
				$bodyScope = $this->processExprNode($condExpr, $bodyScope, static function (): void {
				}, ExpressionContext::createDeep())->getTruthyScope();
			}

			$count = 0;
			do {
				$prevScope = $bodyScope;
				$bodyScope = $bodyScope->mergeWith($initScope);
				foreach ($stmt->cond as $condExpr) {
					$bodyScope = $this->processExprNode($condExpr, $bodyScope, static function (): void {
					}, ExpressionContext::createDeep())->getTruthyScope();
				}
				$bodyScopeResult = $this->processStmtNodes($stmt, $stmt->stmts, $bodyScope, static function (): void {
				})->filterOutLoopExitPoints();
				$alwaysTerminating = $bodyScopeResult->isAlwaysTerminating();
				$bodyScope = $bodyScopeResult->getScope();
				foreach ($bodyScopeResult->getExitPointsByType(Continue_::class) as $continueExitPoint) {
					$bodyScope = $bodyScope->mergeWith($continueExitPoint->getScope());
				}
				foreach ($stmt->loop as $loopExpr) {
					$exprResult = $this->processExprNode($loopExpr, $bodyScope, static function (): void {
					}, ExpressionContext::createTopLevel());
					$bodyScope = $exprResult->getScope();
					$hasYield = $hasYield || $exprResult->hasYield();
				}

				if ($bodyScope->equals($prevScope)) {
					break;
				}

				if ($count >= self::GENERALIZE_AFTER_ITERATION) {
					$bodyScope = $bodyScope->generalizeWith($prevScope);
				}
				$count++;
			} while (!$alwaysTerminating && $count < self::LOOP_SCOPE_ITERATIONS);

			$bodyScope = $bodyScope->mergeWith($initScope);
			foreach ($stmt->cond as $condExpr) {
				$bodyScope = $this->processExprNode($condExpr, $bodyScope, $nodeCallback, ExpressionContext::createDeep())->getTruthyScope();
			}

			$finalScopeResult = $this->processStmtNodes($stmt, $stmt->stmts, $bodyScope, $nodeCallback)->filterOutLoopExitPoints();
			$finalScope = $finalScopeResult->getScope();
			foreach ($finalScopeResult->getExitPointsByType(Continue_::class) as $continueExitPoint) {
				$finalScope = $continueExitPoint->getScope()->mergeWith($finalScope);
			}
			foreach ($stmt->loop as $loopExpr) {
				$finalScope = $this->processExprNode($loopExpr, $finalScope, $nodeCallback, ExpressionContext::createTopLevel())->getScope();
			}
			foreach ($finalScopeResult->getExitPointsByType(Break_::class) as $breakExitPoint) {
				$finalScope = $breakExitPoint->getScope()->mergeWith($finalScope);
			}

			if ($this->polluteScopeWithLoopInitialAssignments) {
				$scope = $initScope;
			}

			$finalScope = $finalScope->mergeWith($scope);

			return new StatementResult(
				$finalScope,
				$finalScopeResult->hasYield() || $hasYield,
				false/* $finalScopeResult->isAlwaysTerminating() && $isAlwaysIterable*/,
				$finalScopeResult->getExitPointsForOuterLoop()
			);
		} elseif ($stmt instanceof Switch_) {
			$condResult = $this->processExprNode($stmt->cond, $scope, $nodeCallback, ExpressionContext::createDeep());
			$scope = $condResult->getScope();
			$scopeForBranches = $scope;
			$finalScope = null;
			$prevScope = null;
			$hasDefaultCase = false;
			$alwaysTerminating = true;
			$hasYield = $condResult->hasYield();
			$exitPointsForOuterLoop = [];
			foreach ($stmt->cases as $caseNode) {
				if ($caseNode->cond !== null) {
					$condExpr = new BinaryOp\Equal($stmt->cond, $caseNode->cond);
					$scopeForBranches = $this->processExprNode($caseNode->cond, $scopeForBranches, $nodeCallback, ExpressionContext::createDeep())->getScope();
					$branchScope = $scopeForBranches->filterByTruthyValue($condExpr);
				} else {
					$hasDefaultCase = true;
					$branchScope = $scopeForBranches;
				}

				$branchScope = $branchScope->mergeWith($prevScope);
				$branchScopeResult = $this->processStmtNodes($caseNode, $caseNode->stmts, $branchScope, $nodeCallback);
				$branchScope = $branchScopeResult->getScope();
				$branchFinalScopeResult = $branchScopeResult->filterOutLoopExitPoints();
				$hasYield = $hasYield || $branchFinalScopeResult->hasYield();
				foreach ($branchScopeResult->getExitPointsByType(Break_::class) as $breakExitPoint) {
					$alwaysTerminating = false;
					$finalScope = $breakExitPoint->getScope()->mergeWith($finalScope);
				}
				foreach ($branchScopeResult->getExitPointsByType(Continue_::class) as $continueExitPoint) {
					$finalScope = $continueExitPoint->getScope()->mergeWith($finalScope);
				}
				$exitPointsForOuterLoop = array_merge($exitPointsForOuterLoop, $branchFinalScopeResult->getExitPointsForOuterLoop());
				if ($branchScopeResult->isAlwaysTerminating()) {
					$alwaysTerminating = $alwaysTerminating && $branchFinalScopeResult->isAlwaysTerminating();
					$prevScope = null;
					if (isset($condExpr)) {
						$scopeForBranches = $scopeForBranches->filterByFalseyValue($condExpr);
					}
					if (!$branchFinalScopeResult->isAlwaysTerminating()) {
						$finalScope = $branchScope->mergeWith($finalScope);
					}
				} else {
					$prevScope = $branchScope;
				}
			}

			if (!$hasDefaultCase) {
				$alwaysTerminating = false;
			}

			if ($prevScope !== null && isset($branchFinalScopeResult)) {
				$finalScope = $prevScope->mergeWith($finalScope);
				$alwaysTerminating = $alwaysTerminating && $branchFinalScopeResult->isAlwaysTerminating();
			}

			if (!$hasDefaultCase || $finalScope === null) {
				$finalScope = $scope->mergeWith($finalScope);
			}

			return new StatementResult($finalScope, $hasYield, $alwaysTerminating, $exitPointsForOuterLoop);
		} elseif ($stmt instanceof TryCatch) {
			$branchScopeResult = $this->processStmtNodes($stmt, $stmt->stmts, $scope, $nodeCallback);
			$branchScope = $branchScopeResult->getScope();
			$tryScope = $branchScope;
			$exitPoints = [];
			$finalScope = $branchScopeResult->isAlwaysTerminating() ? null : $branchScope;
			$alwaysTerminating = $branchScopeResult->isAlwaysTerminating();
			$hasYield = $branchScopeResult->hasYield();

			if ($stmt->finally !== null) {
				$finallyScope = $branchScope;
			} else {
				$finallyScope = null;
			}
			foreach ($branchScopeResult->getExitPoints() as $exitPoint) {
				if ($finallyScope !== null) {
					$finallyScope = $finallyScope->mergeWith($exitPoint->getScope());
				}
				$exitPoints[] = $exitPoint;
			}

			foreach ($stmt->catches as $catchNode) {
				$nodeCallback($catchNode, $scope);
				if (!$this->polluteCatchScopeWithTryAssignments) {
					$catchScopeResult = $this->processCatchNode($catchNode, $scope->mergeWith($tryScope), $nodeCallback);
					$catchScopeForFinally = $catchScopeResult->getScope();
				} else {
					$catchScopeForFinally = $this->processCatchNode($catchNode, $tryScope, $nodeCallback)->getScope();
					$catchScopeResult = $this->processCatchNode($catchNode, $scope->mergeWith($tryScope), static function (): void {
					});
				}

				$finalScope = $catchScopeResult->isAlwaysTerminating() ? $finalScope : $catchScopeResult->getScope()->mergeWith($finalScope);
				$alwaysTerminating = $alwaysTerminating && $catchScopeResult->isAlwaysTerminating();
				$hasYield = $hasYield || $catchScopeResult->hasYield();

				if ($finallyScope !== null) {
					$finallyScope = $finallyScope->mergeWith($catchScopeForFinally);
				}
				foreach ($catchScopeResult->getExitPoints() as $exitPoint) {
					if ($finallyScope !== null) {
						$finallyScope = $finallyScope->mergeWith($exitPoint->getScope());
					}
					$exitPoints[] = $exitPoint;
				}
			}

			if ($finalScope === null) {
				$finalScope = $scope;
			}

			if ($finallyScope !== null && $stmt->finally !== null) {
				$originalFinallyScope = $finallyScope;
				$finallyResult = $this->processStmtNodes($stmt->finally, $stmt->finally->stmts, $finallyScope, $nodeCallback);
				$alwaysTerminating = $alwaysTerminating || $finallyResult->isAlwaysTerminating();
				$hasYield = $hasYield || $finallyResult->hasYield();
				$finallyScope = $finallyResult->getScope();
				$finalScope = $finallyResult->isAlwaysTerminating() ? $finalScope : $finalScope->processFinallyScope($finallyScope, $originalFinallyScope);
				$exitPoints = array_merge($exitPoints, $finallyResult->getExitPoints());
			}

			return new StatementResult($finalScope, $hasYield, $alwaysTerminating, $exitPoints);
		} elseif ($stmt instanceof Unset_) {
			$hasYield = false;
			foreach ($stmt->vars as $var) {
				$scope = $this->lookForEnterVariableAssign($scope, $var);
				$scope = $this->processExprNode($var, $scope, $nodeCallback, ExpressionContext::createDeep())->getScope();
				$scope = $this->lookForExitVariableAssign($scope, $var);
				$scope = $scope->unsetExpression($var);
			}
		} elseif ($stmt instanceof Node\Stmt\Use_) {
			$hasYield = false;
			foreach ($stmt->uses as $use) {
				$this->processStmtNode($use, $scope, $nodeCallback);
			}
		} elseif ($stmt instanceof Node\Stmt\Global_) {
			$hasYield = false;
			$vars = [];
			foreach ($stmt->vars as $var) {
				if (!$var instanceof Variable) {
					throw new \PHPStan\ShouldNotHappenException();
				}
				$scope = $this->lookForEnterVariableAssign($scope, $var);
				$this->processExprNode($var, $scope, $nodeCallback, ExpressionContext::createDeep());
				$scope = $this->lookForExitVariableAssign($scope, $var);

				if (!is_string($var->name)) {
					continue;
				}

				$scope = $scope->assignVariable($var->name, new MixedType());
				$vars[] = $var->name;
			}
			$scope = $this->processVarAnnotation($scope, $vars, $stmt);
		} elseif ($stmt instanceof Static_) {
			$hasYield = false;

			$vars = [];
			foreach ($stmt->vars as $var) {
				$scope = $this->processStmtNode($var, $scope, $nodeCallback)->getScope();
				if (!is_string($var->var->name)) {
					continue;
				}

				$vars[] = $var->var->name;
			}

			$scope = $this->processVarAnnotation($scope, $vars, $stmt);
		} elseif ($stmt instanceof StaticVar) {
			$hasYield = false;
			if (!is_string($stmt->var->name)) {
				throw new \PHPStan\ShouldNotHappenException();
			}
			if ($stmt->default !== null) {
				$this->processExprNode($stmt->default, $scope, $nodeCallback, ExpressionContext::createDeep());
			}
			$scope = $scope->enterExpressionAssign($stmt->var);
			$this->processExprNode($stmt->var, $scope, $nodeCallback, ExpressionContext::createDeep());
			$scope = $scope->exitExpressionAssign($stmt->var);
			$scope = $scope->assignVariable($stmt->var->name, new MixedType());
		} elseif ($stmt instanceof Node\Stmt\Const_ || $stmt instanceof Node\Stmt\ClassConst) {
			$hasYield = false;
			if ($stmt instanceof Node\Stmt\ClassConst) {
				foreach ($stmt->attrGroups as $attrGroup) {
					foreach ($attrGroup->attrs as $attr) {
						foreach ($attr->args as $arg) {
							$nodeCallback($arg->value, $scope);
						}
					}
				}
			}
			foreach ($stmt->consts as $const) {
				$nodeCallback($const, $scope);
				$this->processExprNode($const->value, $scope, $nodeCallback, ExpressionContext::createDeep());
				if ($scope->getNamespace() !== null) {
					$constName = [$scope->getNamespace(), $const->name->toString()];
				} else {
					$constName = $const->name->toString();
				}
				$scope = $scope->specifyExpressionType(new ConstFetch(new Name\FullyQualified($constName)), $scope->getType($const->value));
			}
		} elseif ($stmt instanceof Node\Stmt\Nop) {
			$scope = $this->processStmtVarAnnotation($scope, $stmt, null);
			$hasYield = false;
		} else {
			$hasYield = false;
		}

		return new StatementResult($scope, $hasYield, false, []);
	}

	private function getCurrentClassReflection(Node\Stmt\ClassLike $stmt, Scope $scope): ClassReflection
	{
		$className = $stmt->namespacedName->toString();
		if (!$this->reflectionProvider->hasClass($className)) {
			return $this->createAstClassReflection($stmt, $scope);
		}

		$defaultClassReflection = $this->reflectionProvider->getClass($stmt->namespacedName->toString());
		if ($defaultClassReflection->getFileName() !== $scope->getFile()) {
			return $this->createAstClassReflection($stmt, $scope);
		}

		$startLine = $defaultClassReflection->getNativeReflection()->getStartLine();
		if ($startLine !== $stmt->getStartLine()) {
			return $this->createAstClassReflection($stmt, $scope);
		}

		return $defaultClassReflection;
	}

	private function createAstClassReflection(Node\Stmt\ClassLike $stmt, Scope $scope): ClassReflection
	{
		$nodeToReflection = new NodeToReflection();
		$betterReflectionClass = $nodeToReflection->__invoke(
			$this->classReflector,
			$stmt,
			new LocatedSource(FileReader::read($scope->getFile()), $scope->getFile()),
			$scope->getNamespace() !== null ? new Node\Stmt\Namespace_(new Name($scope->getNamespace())) : null
		);
		if (!$betterReflectionClass instanceof \PHPStan\BetterReflection\Reflection\ReflectionClass) {
			throw new \PHPStan\ShouldNotHappenException();
		}

		return new ClassReflection(
			$this->reflectionProvider,
			$this->fileTypeMapper,
			$this->phpVersion,
			$this->classReflectionExtensionRegistryProvider->getRegistry()->getPropertiesClassReflectionExtensions(),
			$this->classReflectionExtensionRegistryProvider->getRegistry()->getMethodsClassReflectionExtensions(),
			$betterReflectionClass->getName(),
			new ReflectionClass($betterReflectionClass),
			null,
			null,
			null,
			sprintf('%s:%d', $scope->getFile(), $stmt->getStartLine())
		);
	}

	/**
	 * @param Node\Stmt\Catch_ $catchNode
	 * @param MutatingScope $catchScope
	 * @param callable(\PhpParser\Node $node, Scope $scope): void $nodeCallback
	 * @return StatementResult
	 */
	private function processCatchNode(
		Node\Stmt\Catch_ $catchNode,
		MutatingScope $catchScope,
		callable $nodeCallback
	): StatementResult
	{
		$variableName = null;
		if ($catchNode->var !== null) {
			if (!is_string($catchNode->var->name)) {
				throw new \PHPStan\ShouldNotHappenException();
			}

			$variableName = $catchNode->var->name;
		}

		$catchScope = $catchScope->enterCatch($catchNode->types, $variableName);
		return $this->processStmtNodes($catchNode, $catchNode->stmts, $catchScope, $nodeCallback);
	}

	private function lookForEnterVariableAssign(MutatingScope $scope, Expr $expr): MutatingScope
	{
		if (!$expr instanceof ArrayDimFetch || $expr->dim !== null) {
			$scope = $scope->enterExpressionAssign($expr);
		}
		if (!$expr instanceof Variable) {
			return $this->lookForVariableAssignCallback($scope, $expr, static function (MutatingScope $scope, Expr $expr): MutatingScope {
				return $scope->enterExpressionAssign($expr);
			});
		}

		return $scope;
	}

	private function lookForExitVariableAssign(MutatingScope $scope, Expr $expr): MutatingScope
	{
		$scope = $scope->exitExpressionAssign($expr);
		if (!$expr instanceof Variable) {
			return $this->lookForVariableAssignCallback($scope, $expr, static function (MutatingScope $scope, Expr $expr): MutatingScope {
				return $scope->exitExpressionAssign($expr);
			});
		}

		return $scope;
	}

	/**
	 * @param MutatingScope $scope
	 * @param Expr $expr
	 * @param \Closure(MutatingScope $scope, Expr $expr): MutatingScope $callback
	 * @return MutatingScope
	 */
	private function lookForVariableAssignCallback(MutatingScope $scope, Expr $expr, \Closure $callback): MutatingScope
	{
		if ($expr instanceof Variable) {
			$scope = $callback($scope, $expr);
		} elseif ($expr instanceof ArrayDimFetch) {
			while ($expr instanceof ArrayDimFetch) {
				$expr = $expr->var;
			}

			$scope = $this->lookForVariableAssignCallback($scope, $expr, $callback);
		} elseif ($expr instanceof PropertyFetch || $expr instanceof Expr\NullsafePropertyFetch) {
			$scope = $this->lookForVariableAssignCallback($scope, $expr->var, $callback);
		} elseif ($expr instanceof StaticPropertyFetch) {
			if ($expr->class instanceof Expr) {
				$scope = $this->lookForVariableAssignCallback($scope, $expr->class, $callback);
			}
		} elseif ($expr instanceof Array_ || $expr instanceof List_) {
			foreach ($expr->items as $item) {
				if ($item === null) {
					continue;
				}

				$scope = $this->lookForVariableAssignCallback($scope, $item->value, $callback);
			}
		}

		return $scope;
	}

	private function ensureShallowNonNullability(MutatingScope $scope, Expr $exprToSpecify): EnsuredNonNullabilityResult
	{
		$exprType = $scope->getType($exprToSpecify);
		$exprTypeWithoutNull = TypeCombinator::removeNull($exprType);
		if (!$exprType->equals($exprTypeWithoutNull)) {
			$nativeType = $scope->getNativeType($exprToSpecify);
			$scope = $scope->specifyExpressionType(
				$exprToSpecify,
				$exprTypeWithoutNull,
				TypeCombinator::removeNull($nativeType)
			);

			return new EnsuredNonNullabilityResult(
				$scope,
				[
					new EnsuredNonNullabilityResultExpression($exprToSpecify, $exprType, $nativeType),
				]
			);
		}

		return new EnsuredNonNullabilityResult($scope, []);
	}

	private function ensureNonNullability(MutatingScope $scope, Expr $expr, bool $findMethods): EnsuredNonNullabilityResult
	{
		$exprToSpecify = $expr;
		$specifiedExpressions = [];
		while (true) {
			$result = $this->ensureShallowNonNullability($scope, $exprToSpecify);
			$scope = $result->getScope();
			foreach ($result->getSpecifiedExpressions() as $specifiedExpression) {
				$specifiedExpressions[] = $specifiedExpression;
			}

			if ($exprToSpecify instanceof PropertyFetch) {
				$exprToSpecify = $exprToSpecify->var;
			} elseif ($exprToSpecify instanceof StaticPropertyFetch && $exprToSpecify->class instanceof Expr) {
				$exprToSpecify = $exprToSpecify->class;
			} elseif ($findMethods && $exprToSpecify instanceof MethodCall) {
				$exprToSpecify = $exprToSpecify->var;
			} elseif ($findMethods && $exprToSpecify instanceof StaticCall && $exprToSpecify->class instanceof Expr) {
				$exprToSpecify = $exprToSpecify->class;
			} else {
				break;
			}
		}

		return new EnsuredNonNullabilityResult($scope, $specifiedExpressions);
	}

	/**
	 * @param MutatingScope $scope
	 * @param EnsuredNonNullabilityResultExpression[] $specifiedExpressions
	 * @return MutatingScope
	 */
	private function revertNonNullability(MutatingScope $scope, array $specifiedExpressions): MutatingScope
	{
		foreach ($specifiedExpressions as $specifiedExpressionResult) {
			$scope = $scope->specifyExpressionType(
				$specifiedExpressionResult->getExpression(),
				$specifiedExpressionResult->getOriginalType(),
				$specifiedExpressionResult->getOriginalNativeType()
			);
		}

		return $scope;
	}

	private function findEarlyTerminatingExpr(Expr $expr, Scope $scope): ?Expr
	{
		if (($expr instanceof MethodCall || $expr instanceof Expr\StaticCall) && $expr->name instanceof Node\Identifier) {
			if (count($this->earlyTerminatingMethodCalls) > 0) {
				if ($expr instanceof MethodCall) {
					$methodCalledOnType = $scope->getType($expr->var);
				} else {
					if ($expr->class instanceof Name) {
						$methodCalledOnType = $scope->resolveTypeByName($expr->class);
					} else {
						$methodCalledOnType = $scope->getType($expr->class);
					}
				}

				$directClassNames = TypeUtils::getDirectClassNames($methodCalledOnType);
				foreach ($directClassNames as $referencedClass) {
					if (!$this->reflectionProvider->hasClass($referencedClass)) {
						continue;
					}

					$classReflection = $this->reflectionProvider->getClass($referencedClass);
					foreach (array_merge([$referencedClass], $classReflection->getParentClassesNames(), $classReflection->getNativeReflection()->getInterfaceNames()) as $className) {
						if (!isset($this->earlyTerminatingMethodCalls[$className])) {
							continue;
						}

						if (in_array((string) $expr->name, $this->earlyTerminatingMethodCalls[$className], true)) {
							return $expr;
						}
					}
				}
			}
		}

		if ($expr instanceof FuncCall && $expr->name instanceof Name) {
			if (in_array((string) $expr->name, $this->earlyTerminatingFunctionCalls, true)) {
				return $expr;
			}
		}

		$exprType = $scope->getType($expr);
		if ($exprType instanceof NeverType && $exprType->isExplicit()) {
			return $expr;
		}

		return null;
	}

	/**
	 * @param \PhpParser\Node\Expr $expr
	 * @param \PHPStan\Analyser\MutatingScope $scope
	 * @param callable(\PhpParser\Node $node, Scope $scope): void $nodeCallback
	 * @param \PHPStan\Analyser\ExpressionContext $context
	 * @return \PHPStan\Analyser\ExpressionResult
	 */
	private function processExprNode(Expr $expr, MutatingScope $scope, callable $nodeCallback, ExpressionContext $context): ExpressionResult
	{
		$this->callNodeCallbackWithExpression($nodeCallback, $expr, $scope, $context);

		if ($expr instanceof Variable) {
			$hasYield = false;
			if ($expr->name instanceof Expr) {
				return $this->processExprNode($expr->name, $scope, $nodeCallback, $context->enterDeep());
			}
		} elseif ($expr instanceof Assign || $expr instanceof AssignRef) {
			if (!$expr->var instanceof Array_ && !$expr->var instanceof List_) {
				$result = $this->processAssignVar(
					$scope,
					$expr->var,
					$expr->expr,
					$nodeCallback,
					$context,
					function (MutatingScope $scope) use ($expr, $nodeCallback, $context): ExpressionResult {
						if ($expr instanceof AssignRef) {
							$scope = $scope->enterExpressionAssign($expr->expr);
						}

						if ($expr->var instanceof Variable && is_string($expr->var->name)) {
							$context = $context->enterRightSideAssign(
								$expr->var->name,
								$scope->getType($expr->expr)
							);
						}

						$result = $this->processExprNode($expr->expr, $scope, $nodeCallback, $context->enterDeep());
						$hasYield = $result->hasYield();
						$scope = $result->getScope();

						if ($expr instanceof AssignRef) {
							$scope = $scope->exitExpressionAssign($expr->expr);
						}

						return new ExpressionResult($scope, $hasYield);
					},
					true
				);
				$scope = $result->getScope();
				$hasYield = $result->hasYield();
				$varChangedScope = false;
				if ($expr->var instanceof Variable && is_string($expr->var->name)) {
					$scope = $this->processVarAnnotation($scope, [$expr->var->name], $expr, $varChangedScope);
				}

				if (!$varChangedScope) {
					$scope = $this->processStmtVarAnnotation($scope, new Node\Stmt\Expression($expr, [
						'comments' => $expr->getAttribute('comments'),
					]), null);
				}
			} else {
				$result = $this->processExprNode($expr->expr, $scope, $nodeCallback, $context->enterDeep());
				$hasYield = $result->hasYield();
				$scope = $result->getScope();
				foreach ($expr->var->items as $arrayItem) {
					if ($arrayItem === null) {
						continue;
					}

					$itemScope = $scope;
					if ($arrayItem->value instanceof ArrayDimFetch && $arrayItem->value->dim === null) {
						$itemScope = $itemScope->enterExpressionAssign($arrayItem->value);
					}
					$itemScope = $this->lookForEnterVariableAssign($itemScope, $arrayItem->value);

					$this->processExprNode($arrayItem, $itemScope, $nodeCallback, $context->enterDeep());
				}
				$scope = $this->lookForArrayDestructuringArray($scope, $expr->var, $scope->getType($expr->expr));
				$vars = $this->getAssignedVariables($expr->var);

				if (count($vars) > 0) {
					$varChangedScope = false;
					$scope = $this->processVarAnnotation($scope, $vars, $expr, $varChangedScope);
					if (!$varChangedScope) {
						$scope = $this->processStmtVarAnnotation($scope, new Node\Stmt\Expression($expr, [
							'comments' => $expr->getAttribute('comments'),
						]), null);
					}
				}
			}
		} elseif ($expr instanceof Expr\AssignOp) {
			$result = $this->processAssignVar(
				$scope,
				$expr->var,
				$expr,
				$nodeCallback,
				$context,
				function (MutatingScope $scope) use ($expr, $nodeCallback, $context): ExpressionResult {
					return $this->processExprNode($expr->expr, $scope, $nodeCallback, $context->enterDeep());
				},
				$expr instanceof Expr\AssignOp\Coalesce
			);
			$scope = $result->getScope();
			$hasYield = $result->hasYield();
		} elseif ($expr instanceof FuncCall) {
			$parametersAcceptor = null;
			$functionReflection = null;
			if ($expr->name instanceof Expr) {
				$scope = $this->processExprNode($expr->name, $scope, $nodeCallback, $context->enterDeep())->getScope();
			} elseif ($this->reflectionProvider->hasFunction($expr->name, $scope)) {
				$functionReflection = $this->reflectionProvider->getFunction($expr->name, $scope);
				$parametersAcceptor = ParametersAcceptorSelector::selectFromArgs(
					$scope,
					$expr->args,
					$functionReflection->getVariants()
				);
			}
			$result = $this->processArgs($functionReflection, $parametersAcceptor, $expr->args, $scope, $nodeCallback, $context);
			$scope = $result->getScope();
			$hasYield = $result->hasYield();

			if (
				isset($functionReflection)
				&& in_array($functionReflection->getName(), ['json_encode', 'json_decode'], true)
			) {
				$scope = $scope->invalidateExpression(new FuncCall(new Name('json_last_error'), []))
					->invalidateExpression(new FuncCall(new Name\FullyQualified('json_last_error'), []))
					->invalidateExpression(new FuncCall(new Name('json_last_error_msg'), []))
					->invalidateExpression(new FuncCall(new Name\FullyQualified('json_last_error_msg'), []));
			}

			if (
				isset($functionReflection)
				&& in_array($functionReflection->getName(), ['array_pop', 'array_shift'], true)
				&& count($expr->args) >= 1
			) {
				$arrayArg = $expr->args[0]->value;
				$constantArrays = TypeUtils::getConstantArrays($scope->getType($arrayArg));
				$scope = $scope->invalidateExpression($arrayArg)
					->invalidateExpression(new FuncCall(new Name\FullyQualified('count'), [$expr->args[0]]))
					->invalidateExpression(new FuncCall(new Name('count'), [$expr->args[0]]));
				if (count($constantArrays) > 0) {
					$resultArrayTypes = [];

					foreach ($constantArrays as $constantArray) {
						if ($functionReflection->getName() === 'array_pop') {
							$resultArrayTypes[] = $constantArray->removeLast();
						} else {
							$resultArrayTypes[] = $constantArray->removeFirst();
						}
					}

					$scope = $scope->specifyExpressionType(
						$arrayArg,
						TypeCombinator::union(...$resultArrayTypes)
					);
				} else {
					$arrays = TypeUtils::getAnyArrays($scope->getType($arrayArg));
					if (count($arrays) > 0) {
						$scope = $scope->specifyExpressionType($arrayArg, TypeCombinator::union(...$arrays));
					}
				}
			}

			if (
				isset($functionReflection)
				&& in_array($functionReflection->getName(), ['array_push', 'array_unshift'], true)
				&& count($expr->args) >= 2
			) {
				$argumentTypes = [];
				foreach (array_slice($expr->args, 1) as $callArg) {
					$callArgType = $scope->getType($callArg->value);
					if ($callArg->unpack) {
						$iterableValueType = $callArgType->getIterableValueType();
						if ($iterableValueType instanceof UnionType) {
							foreach ($iterableValueType->getTypes() as $innerType) {
								$argumentTypes[] = $innerType;
							}
						} else {
							$argumentTypes[] = $iterableValueType;
						}
						continue;
					}

					$argumentTypes[] = $callArgType;
				}

				$arrayArg = $expr->args[0]->value;
				$originalArrayType = $scope->getType($arrayArg);
				$constantArrays = TypeUtils::getConstantArrays($originalArrayType);
				if (
					$functionReflection->getName() === 'array_push'
					|| ($originalArrayType->isArray()->yes() && count($constantArrays) === 0)
				) {
					$arrayType = $originalArrayType;
					foreach ($argumentTypes as $argType) {
						$arrayType = $arrayType->setOffsetValueType(null, $argType);
					}

					$scope = $scope->invalidateExpression($arrayArg)->specifyExpressionType($arrayArg, TypeCombinator::intersect($arrayType, new NonEmptyArrayType()));
				} elseif (count($constantArrays) > 0) {
					$defaultArrayBuilder = ConstantArrayTypeBuilder::createEmpty();
					foreach ($argumentTypes as $argType) {
						$defaultArrayBuilder->setOffsetValueType(null, $argType);
					}

					$defaultArrayType = $defaultArrayBuilder->getArray();

					$arrayTypes = [];
					foreach ($constantArrays as $constantArray) {
						$arrayType = $defaultArrayType;
						foreach ($constantArray->getKeyTypes() as $i => $keyType) {
							$valueType = $constantArray->getValueTypes()[$i];
							if ($keyType instanceof ConstantIntegerType) {
								$keyType = null;
							}
							$arrayType = $arrayType->setOffsetValueType($keyType, $valueType);
						}
						$arrayTypes[] = $arrayType;
					}

					$scope = $scope->invalidateExpression($arrayArg)->specifyExpressionType(
						$arrayArg,
						TypeCombinator::union(...$arrayTypes)
					);
				}
			}

			if (
				isset($functionReflection)
				&& in_array($functionReflection->getName(), ['fopen', 'file_get_contents'], true)
			) {
				$scope = $scope->assignVariable('http_response_header', new ArrayType(new IntegerType(), new StringType()));
			}

			if (isset($functionReflection) && $functionReflection->getName() === 'extract') {
				$scope = $scope->afterExtractCall();
			}
		} elseif ($expr instanceof MethodCall) {
			$originalScope = $scope;
			if (
				($expr->var instanceof Expr\Closure || $expr->var instanceof Expr\ArrowFunction)
				&& $expr->name instanceof Node\Identifier
				&& strtolower($expr->name->name) === 'call'
				&& isset($expr->args[0])
			) {
				$closureCallScope = $scope->enterClosureCall($scope->getType($expr->args[0]->value));
			}

			$result = $this->processExprNode($expr->var, $closureCallScope ?? $scope, $nodeCallback, $context->enterDeep());
			$hasYield = $result->hasYield();
			$scope = $result->getScope();
			if (isset($closureCallScope)) {
				$scope = $scope->restoreOriginalScopeAfterClosureBind($originalScope);
			}
			$parametersAcceptor = null;
			$methodReflection = null;
			if ($expr->name instanceof Expr) {
				$scope = $this->processExprNode($expr->name, $scope, $nodeCallback, $context->enterDeep())->getScope();
			} else {
				$calledOnType = $scope->getType($expr->var);
				$methodName = $expr->name->name;
				if ($calledOnType->hasMethod($methodName)->yes()) {
					$methodReflection = $calledOnType->getMethod($methodName, $scope);
					$parametersAcceptor = ParametersAcceptorSelector::selectFromArgs(
						$scope,
						$expr->args,
						$methodReflection->getVariants()
					);
				}
			}
			$result = $this->processArgs($methodReflection, $parametersAcceptor, $expr->args, $scope, $nodeCallback, $context);
			$scope = $result->getScope();
			if ($methodReflection !== null && $methodReflection->hasSideEffects()->yes()) {
				$scope = $scope->invalidateExpression($expr->var, true);
			}
			$hasYield = $hasYield || $result->hasYield();
		} elseif ($expr instanceof Expr\NullsafeMethodCall) {
			$nonNullabilityResult = $this->ensureShallowNonNullability($scope, $expr->var);
			$exprResult = $this->processExprNode(new MethodCall($expr->var, $expr->name, $expr->args, $expr->getAttributes()), $nonNullabilityResult->getScope(), $nodeCallback, $context);
			$scope = $this->revertNonNullability($exprResult->getScope(), $nonNullabilityResult->getSpecifiedExpressions());

			return new ExpressionResult($scope, $exprResult->hasYield());
		} elseif ($expr instanceof StaticCall) {
			if ($expr->class instanceof Expr) {
				$scope = $this->processExprNode($expr->class, $scope, $nodeCallback, $context->enterDeep())->getScope();
			}

			$parametersAcceptor = null;
			$methodReflection = null;
			$hasYield = false;
			if ($expr->name instanceof Expr) {
				$result = $this->processExprNode($expr->name, $scope, $nodeCallback, $context->enterDeep());
				$hasYield = $result->hasYield();
				$scope = $result->getScope();
			} elseif ($expr->class instanceof Name) {
				$className = $scope->resolveName($expr->class);
				if ($this->reflectionProvider->hasClass($className)) {
					$classReflection = $this->reflectionProvider->getClass($className);
					if (is_string($expr->name)) {
						$methodName = $expr->name;
					} else {
						$methodName = $expr->name->name;
					}
					if ($classReflection->hasMethod($methodName)) {
						$methodReflection = $classReflection->getMethod($methodName, $scope);
						$parametersAcceptor = ParametersAcceptorSelector::selectFromArgs(
							$scope,
							$expr->args,
							$methodReflection->getVariants()
						);
						if (
							$classReflection->getName() === 'Closure'
							&& strtolower($methodName) === 'bind'
						) {
							$thisType = null;
							if (isset($expr->args[1])) {
								$argType = $scope->getType($expr->args[1]->value);
								if ($argType instanceof NullType) {
									$thisType = null;
								} else {
									$thisType = $argType;
								}
							}
							$scopeClass = 'static';
							if (isset($expr->args[2])) {
								$argValue = $expr->args[2]->value;
								$argValueType = $scope->getType($argValue);

								$directClassNames = TypeUtils::getDirectClassNames($argValueType);
								if (count($directClassNames) === 1) {
									$scopeClass = $directClassNames[0];
								} elseif (
									$argValue instanceof Expr\ClassConstFetch
									&& $argValue->name instanceof Node\Identifier
									&& strtolower($argValue->name->name) === 'class'
									&& $argValue->class instanceof Name
								) {
									$scopeClass = $scope->resolveName($argValue->class);
								} elseif ($argValueType instanceof ConstantStringType) {
									$scopeClass = $argValueType->getValue();
								}
							}
							$closureBindScope = $scope->enterClosureBind($thisType, $scopeClass);
						}
					}
				}
			}
			$result = $this->processArgs($methodReflection, $parametersAcceptor, $expr->args, $scope, $nodeCallback, $context, $closureBindScope ?? null);
			$scope = $result->getScope();
			$scopeFunction = $scope->getFunction();
			if (
				$methodReflection !== null
				&& !$methodReflection->isStatic()
				&& $methodReflection->hasSideEffects()->yes()
				&& $scopeFunction instanceof MethodReflection
				&& !$scopeFunction->isStatic()
				&& $scope->isInClass()
				&& (
					$scope->getClassReflection()->getName() === $methodReflection->getDeclaringClass()->getName()
					|| $scope->getClassReflection()->isSubclassOf($methodReflection->getDeclaringClass()->getName())
				)
			) {
				$scope = $scope->invalidateExpression(new Variable('this'), true);
			}
			$hasYield = $hasYield || $result->hasYield();
		} elseif ($expr instanceof PropertyFetch) {
			$result = $this->processExprNode($expr->var, $scope, $nodeCallback, $context->enterDeep());
			$hasYield = $result->hasYield();
			$scope = $result->getScope();
			if ($expr->name instanceof Expr) {
				$result = $this->processExprNode($expr->name, $scope, $nodeCallback, $context->enterDeep());
				$hasYield = $hasYield || $result->hasYield();
				$scope = $result->getScope();
			}
		} elseif ($expr instanceof Expr\NullsafePropertyFetch) {
			$nonNullabilityResult = $this->ensureShallowNonNullability($scope, $expr->var);
			$exprResult = $this->processExprNode(new PropertyFetch($expr->var, $expr->name, $expr->getAttributes()), $nonNullabilityResult->getScope(), $nodeCallback, $context);
			$scope = $this->revertNonNullability($exprResult->getScope(), $nonNullabilityResult->getSpecifiedExpressions());

			return new ExpressionResult(
				$scope,
				$exprResult->hasYield(),
				static function () use ($scope, $expr): MutatingScope {
					return $scope->filterByTruthyValue($expr);
				},
				static function () use ($scope, $expr): MutatingScope {
					return $scope->filterByFalseyValue($expr);
				}
			);
		} elseif ($expr instanceof StaticPropertyFetch) {
			$hasYield = false;
			if ($expr->class instanceof Expr) {
				$result = $this->processExprNode($expr->class, $scope, $nodeCallback, $context->enterDeep());
				$hasYield = $result->hasYield();
				$scope = $result->getScope();
			}
			if ($expr->name instanceof Expr) {
				$result = $this->processExprNode($expr->name, $scope, $nodeCallback, $context->enterDeep());
				$hasYield = $hasYield || $result->hasYield();
				$scope = $result->getScope();
			}
		} elseif ($expr instanceof Expr\Closure) {
			return $this->processClosureNode($expr, $scope, $nodeCallback, $context, null);
		} elseif ($expr instanceof Expr\ClosureUse) {
			$this->processExprNode($expr->var, $scope, $nodeCallback, $context);
			$hasYield = false;
		} elseif ($expr instanceof Expr\ArrowFunction) {
			foreach ($expr->params as $param) {
				$this->processParamNode($param, $scope, $nodeCallback);
			}
			if ($expr->returnType !== null) {
				$nodeCallback($expr->returnType, $scope);
			}

			$arrowFunctionScope = $scope->enterArrowFunction($expr);
			$nodeCallback(new InArrowFunctionNode($expr), $arrowFunctionScope);
			$this->processExprNode($expr->expr, $arrowFunctionScope, $nodeCallback, ExpressionContext::createTopLevel());
			$hasYield = false;

		} elseif ($expr instanceof ErrorSuppress) {
			$result = $this->processExprNode($expr->expr, $scope, $nodeCallback, $context);
			$hasYield = $result->hasYield();
			$scope = $result->getScope();
		} elseif ($expr instanceof Exit_) {
			$hasYield = false;
			if ($expr->expr !== null) {
				$result = $this->processExprNode($expr->expr, $scope, $nodeCallback, $context->enterDeep());
				$hasYield = $result->hasYield();
				$scope = $result->getScope();
			}
		} elseif ($expr instanceof Node\Scalar\Encapsed) {
			$hasYield = false;
			foreach ($expr->parts as $part) {
				$result = $this->processExprNode($part, $scope, $nodeCallback, $context->enterDeep());
				$hasYield = $hasYield || $result->hasYield();
				$scope = $result->getScope();
			}
		} elseif ($expr instanceof ArrayDimFetch) {
			$hasYield = false;
			if ($expr->dim !== null) {
				$result = $this->processExprNode($expr->dim, $scope, $nodeCallback, $context->enterDeep());
				$hasYield = $result->hasYield();
				$scope = $result->getScope();
			}

			$result = $this->processExprNode($expr->var, $scope, $nodeCallback, $context->enterDeep());
			$hasYield = $hasYield || $result->hasYield();
			$scope = $result->getScope();
		} elseif ($expr instanceof Array_) {
			$itemNodes = [];
			$hasYield = false;
			foreach ($expr->items as $arrayItem) {
				$itemNodes[] = new LiteralArrayItem($scope, $arrayItem);
				if ($arrayItem === null) {
					continue;
				}
				$result = $this->processExprNode($arrayItem, $scope, $nodeCallback, $context->enterDeep());
				$hasYield = $hasYield || $result->hasYield();
				$scope = $result->getScope();
			}
			$nodeCallback(new LiteralArrayNode($expr, $itemNodes), $scope);
		} elseif ($expr instanceof ArrayItem) {
			$hasYield = false;
			if ($expr->key !== null) {
				$result = $this->processExprNode($expr->key, $scope, $nodeCallback, $context->enterDeep());
				$hasYield = $result->hasYield();
				$scope = $result->getScope();
			}
			$result = $this->processExprNode($expr->value, $scope, $nodeCallback, $context->enterDeep());
			$hasYield = $hasYield || $result->hasYield();
			$scope = $result->getScope();
		} elseif ($expr instanceof BooleanAnd || $expr instanceof BinaryOp\LogicalAnd) {
			$leftResult = $this->processExprNode($expr->left, $scope, $nodeCallback, $context->enterDeep());
			$rightResult = $this->processExprNode($expr->right, $leftResult->getTruthyScope(), $nodeCallback, $context);
			$leftMergedWithRightScope = $leftResult->getScope()->mergeWith($rightResult->getScope());

			$nodeCallback(new BooleanAndNode($expr, $leftResult->getTruthyScope()), $scope);

			return new ExpressionResult(
				$leftMergedWithRightScope,
				$leftResult->hasYield() || $rightResult->hasYield(),
				static function () use ($expr, $rightResult): MutatingScope {
					return $rightResult->getScope()->filterByTruthyValue($expr);
				},
				static function () use ($leftMergedWithRightScope, $expr): MutatingScope {
					return $leftMergedWithRightScope->filterByFalseyValue($expr);
				}
			);
		} elseif ($expr instanceof BooleanOr || $expr instanceof BinaryOp\LogicalOr) {
			$leftResult = $this->processExprNode($expr->left, $scope, $nodeCallback, $context->enterDeep());
			$rightResult = $this->processExprNode($expr->right, $leftResult->getFalseyScope(), $nodeCallback, $context);
			$leftMergedWithRightScope = $leftResult->getScope()->mergeWith($rightResult->getScope());

			$nodeCallback(new BooleanOrNode($expr, $leftResult->getFalseyScope()), $scope);

			return new ExpressionResult(
				$leftMergedWithRightScope,
				$leftResult->hasYield() || $rightResult->hasYield(),
				static function () use ($leftMergedWithRightScope, $expr): MutatingScope {
					return $leftMergedWithRightScope->filterByTruthyValue($expr);
				},
				static function () use ($expr, $rightResult): MutatingScope {
					return $rightResult->getScope()->filterByFalseyValue($expr);
				}
			);
		} elseif ($expr instanceof Coalesce) {
			$nonNullabilityResult = $this->ensureNonNullability($scope, $expr->left, false);

			if ($expr->left instanceof PropertyFetch || $expr->left instanceof StaticPropertyFetch || $expr->left instanceof Expr\NullsafePropertyFetch) {
				$scope = $nonNullabilityResult->getScope();
			} else {
				$scope = $this->lookForEnterVariableAssign($nonNullabilityResult->getScope(), $expr->left);
			}
			$result = $this->processExprNode($expr->left, $scope, $nodeCallback, $context->enterDeep());
			$hasYield = $result->hasYield();
			$scope = $result->getScope();
			$scope = $this->revertNonNullability($scope, $nonNullabilityResult->getSpecifiedExpressions());

			if (!$expr->left instanceof PropertyFetch) {
				$scope = $this->lookForExitVariableAssign($scope, $expr->left);
			}
			$result = $this->processExprNode($expr->right, $scope, $nodeCallback, $context->enterDeep());
			$scope = $result->getScope()->mergeWith($scope);
			$hasYield = $hasYield || $result->hasYield();
		} elseif ($expr instanceof BinaryOp) {
			$result = $this->processExprNode($expr->left, $scope, $nodeCallback, $context->enterDeep());
			$scope = $result->getScope();
			$hasYield = $result->hasYield();
			$result = $this->processExprNode($expr->right, $scope, $nodeCallback, $context->enterDeep());
			$scope = $result->getScope();
			$hasYield = $hasYield || $result->hasYield();
		} elseif (
			$expr instanceof Expr\BitwiseNot
			|| $expr instanceof Cast
			|| $expr instanceof Expr\Clone_
			|| $expr instanceof Expr\Eval_
			|| $expr instanceof Expr\Include_
			|| $expr instanceof Expr\Print_
			|| $expr instanceof Expr\UnaryMinus
			|| $expr instanceof Expr\UnaryPlus
			|| $expr instanceof Expr\YieldFrom
		) {
			$result = $this->processExprNode($expr->expr, $scope, $nodeCallback, $context->enterDeep());
			if ($expr instanceof Expr\YieldFrom) {
				$hasYield = true;
			} else {
				$hasYield = $result->hasYield();
			}

			$scope = $result->getScope();
		} elseif ($expr instanceof BooleanNot) {
			$result = $this->processExprNode($expr->expr, $scope, $nodeCallback, $context->enterDeep());
			$scope = $result->getScope();
			$hasYield = $result->hasYield();
		} elseif ($expr instanceof Expr\ClassConstFetch) {
			$hasYield = false;
			if ($expr->class instanceof Expr) {
				$result = $this->processExprNode($expr->class, $scope, $nodeCallback, $context->enterDeep());
				$scope = $result->getScope();
				$hasYield = $result->hasYield();
			}
		} elseif ($expr instanceof Expr\Empty_) {
			$nonNullabilityResult = $this->ensureNonNullability($scope, $expr->expr, true);
			$scope = $this->lookForEnterVariableAssign($nonNullabilityResult->getScope(), $expr->expr);
			$result = $this->processExprNode($expr->expr, $scope, $nodeCallback, $context->enterDeep());
			$scope = $result->getScope();
			$hasYield = $result->hasYield();
			$scope = $this->revertNonNullability($scope, $nonNullabilityResult->getSpecifiedExpressions());
			$scope = $this->lookForExitVariableAssign($scope, $expr->expr);
		} elseif ($expr instanceof Expr\Isset_) {
			$hasYield = false;
			$nonNullabilityResults = [];
			foreach ($expr->vars as $var) {
				$nonNullabilityResult = $this->ensureNonNullability($scope, $var, true);
				$scope = $this->lookForEnterVariableAssign($nonNullabilityResult->getScope(), $var);
				$result = $this->processExprNode($var, $scope, $nodeCallback, $context->enterDeep());
				$scope = $result->getScope();
				$hasYield = $hasYield || $result->hasYield();
				$nonNullabilityResults[] = $nonNullabilityResult;
				$scope = $this->lookForExitVariableAssign($scope, $var);
			}
			foreach (array_reverse($nonNullabilityResults) as $nonNullabilityResult) {
				$scope = $this->revertNonNullability($scope, $nonNullabilityResult->getSpecifiedExpressions());
			}
		} elseif ($expr instanceof Instanceof_) {
			$result = $this->processExprNode($expr->expr, $scope, $nodeCallback, $context->enterDeep());
			$scope = $result->getScope();
			$hasYield = $result->hasYield();
			if ($expr->class instanceof Expr) {
				$result = $this->processExprNode($expr->class, $scope, $nodeCallback, $context->enterDeep());
				$scope = $result->getScope();
				$hasYield = $hasYield || $result->hasYield();
			}
		} elseif ($expr instanceof List_) {
			// only in assign and foreach, processed elsewhere
			return new ExpressionResult($scope, false);
		} elseif ($expr instanceof New_) {
			$parametersAcceptor = null;
			$constructorReflection = null;
			$hasYield = false;
			if ($expr->class instanceof Expr) {
				$result = $this->processExprNode($expr->class, $scope, $nodeCallback, $context->enterDeep());
				$scope = $result->getScope();
				$hasYield = $result->hasYield();
			} elseif ($expr->class instanceof Class_) {
				$this->reflectionProvider->getAnonymousClassReflection($expr->class, $scope); // populates $expr->class->name
				$this->processStmtNode($expr->class, $scope, $nodeCallback);
			} else {
				$className = $scope->resolveName($expr->class);
				if ($this->reflectionProvider->hasClass($className)) {
					$classReflection = $this->reflectionProvider->getClass($className);
					if ($classReflection->hasConstructor()) {
						$constructorReflection = $classReflection->getConstructor();
						$parametersAcceptor = ParametersAcceptorSelector::selectFromArgs(
							$scope,
							$expr->args,
							$constructorReflection->getVariants()
						);
					}
				}
			}
			$result = $this->processArgs($constructorReflection, $parametersAcceptor, $expr->args, $scope, $nodeCallback, $context);
			$scope = $result->getScope();
			$hasYield = $hasYield || $result->hasYield();
		} elseif (
			$expr instanceof Expr\PreInc
			|| $expr instanceof Expr\PostInc
			|| $expr instanceof Expr\PreDec
			|| $expr instanceof Expr\PostDec
		) {
			$result = $this->processExprNode($expr->var, $scope, $nodeCallback, $context->enterDeep());
			$scope = $result->getScope();
			$hasYield = $result->hasYield();
			if (
				$expr->var instanceof Variable
				|| $expr->var instanceof ArrayDimFetch
				|| $expr->var instanceof PropertyFetch
				|| $expr->var instanceof StaticPropertyFetch
			) {
				$newExpr = $expr;
				if ($expr instanceof Expr\PostInc) {
					$newExpr = new Expr\PreInc($expr->var);
				} elseif ($expr instanceof Expr\PostDec) {
					$newExpr = new Expr\PreDec($expr->var);
				}

				if (!$scope->getType($expr->var)->equals($scope->getType($newExpr))) {
					$scope = $this->processAssignVar(
						$scope,
						$expr->var,
						$newExpr,
						static function (): void {
						},
						$context,
						static function (MutatingScope $scope): ExpressionResult {
							return new ExpressionResult($scope, false);
						},
						false
					)->getScope();
				} else {
					$scope = $scope->invalidateExpression($expr->var);
				}
			}
		} elseif ($expr instanceof Ternary) {
			$ternaryCondResult = $this->processExprNode($expr->cond, $scope, $nodeCallback, $context->enterDeep());
			$ifTrueScope = $ternaryCondResult->getTruthyScope();
			$ifFalseScope = $ternaryCondResult->getFalseyScope();

			if ($expr->if !== null) {
				$ifTrueScope = $this->processExprNode($expr->if, $ifTrueScope, $nodeCallback, $context)->getScope();
				$ifFalseScope = $this->processExprNode($expr->else, $ifFalseScope, $nodeCallback, $context)->getScope();
			} else {
				$ifFalseScope = $this->processExprNode($expr->else, $ifFalseScope, $nodeCallback, $context)->getScope();
			}

			$finalScope = $ifTrueScope->mergeWith($ifFalseScope);

			return new ExpressionResult(
				$finalScope,
				$ternaryCondResult->hasYield(),
				static function () use ($finalScope, $expr): MutatingScope {
					return $finalScope->filterByTruthyValue($expr);
				},
				static function () use ($finalScope, $expr): MutatingScope {
					return $finalScope->filterByFalseyValue($expr);
				}
			);

		} elseif ($expr instanceof Expr\Yield_) {
			if ($expr->key !== null) {
				$scope = $this->processExprNode($expr->key, $scope, $nodeCallback, $context->enterDeep())->getScope();
			}
			if ($expr->value !== null) {
				$scope = $this->processExprNode($expr->value, $scope, $nodeCallback, $context->enterDeep())->getScope();
			}
			$hasYield = true;
		} elseif ($expr instanceof Expr\Match_) {
			$deepContext = $context->enterDeep();
			$condResult = $this->processExprNode($expr->cond, $scope, $nodeCallback, $deepContext);
			$scope = $condResult->getScope();
			$hasYield = $condResult->hasYield();
			$matchScope = $scope;
			$armNodes = [];
			foreach ($expr->arms as $arm) {
				if ($arm->conds === null) {
					$armResult = $this->processExprNode($arm->body, $matchScope, $nodeCallback, $deepContext);
					$matchScope = $armResult->getScope();
					$hasYield = $hasYield || $armResult->hasYield();
					$scope = $scope->mergeWith($matchScope);
					$armNodes[] = new MatchExpressionArm([], $arm->getLine());
					continue;
				}

				if (count($arm->conds) === 0) {
					throw new \PHPStan\ShouldNotHappenException();
				}

				$filteringExpr = null;
				$armCondScope = $matchScope;
				$condNodes = [];
				foreach ($arm->conds as $armCond) {
					$condNodes[] = new MatchExpressionArmCondition($armCond, $armCondScope, $armCond->getLine());
					$armCondResult = $this->processExprNode($armCond, $armCondScope, $nodeCallback, $deepContext);
					$hasYield = $hasYield || $armCondResult->hasYield();
					$armCondExpr = new BinaryOp\Identical($expr->cond, $armCond);
					$armCondScope = $armCondResult->getScope()->filterByFalseyValue($armCondExpr);
					if ($filteringExpr === null) {
						$filteringExpr = $armCondExpr;
						continue;
					}

					$filteringExpr = new BinaryOp\BooleanOr($filteringExpr, $armCondExpr);
				}

				$armNodes[] = new MatchExpressionArm($condNodes, $arm->getLine());

				$armResult = $this->processExprNode(
					$arm->body,
					$matchScope->filterByTruthyValue($filteringExpr),
					$nodeCallback,
					$deepContext
				);
				$armScope = $armResult->getScope();
				$scope = $scope->mergeWith($armScope);
				$hasYield = $hasYield || $armResult->hasYield();
				$matchScope = $matchScope->filterByFalseyValue($filteringExpr);
			}

			$nodeCallback(new MatchExpressionNode($expr->cond, $armNodes, $expr, $matchScope), $scope);
		} else {
			$hasYield = false;
		}

		return new ExpressionResult(
			$scope,
			$hasYield,
			static function () use ($scope, $expr): MutatingScope {
				return $scope->filterByTruthyValue($expr);
			},
			static function () use ($scope, $expr): MutatingScope {
				return $scope->filterByFalseyValue($expr);
			}
		);
	}

	/**
	 * @param Expr $expr
	 * @return string[]
	 */
	private function getAssignedVariables(Expr $expr): array
	{
		if ($expr instanceof Expr\Variable) {
			if (is_string($expr->name)) {
				return [$expr->name];
			}

			return [];
		}

		if ($expr instanceof Expr\List_ || $expr instanceof Expr\Array_) {
			$names = [];
			foreach ($expr->items as $item) {
				if ($item === null) {
					continue;
				}

				$names = array_merge($names, $this->getAssignedVariables($item->value));
			}

			return $names;
		}

		return [];
	}

	/**
	 * @param callable(\PhpParser\Node $node, Scope $scope): void $nodeCallback
	 * @param Expr $expr
	 * @param MutatingScope $scope
	 * @param ExpressionContext $context
	 */
	private function callNodeCallbackWithExpression(
		callable $nodeCallback,
		Expr $expr,
		MutatingScope $scope,
		ExpressionContext $context
	): void
	{
		if ($context->isDeep()) {
			$scope = $scope->exitFirstLevelStatements();
		}
		$nodeCallback($expr, $scope);
	}

	/**
	 * @param \PhpParser\Node\Expr\Closure $expr
	 * @param \PHPStan\Analyser\MutatingScope $scope
	 * @param callable(\PhpParser\Node $node, Scope $scope): void $nodeCallback
	 * @param ExpressionContext $context
	 * @param Type|null $passedToType
	 * @return \PHPStan\Analyser\ExpressionResult
	 */
	private function processClosureNode(
		Expr\Closure $expr,
		MutatingScope $scope,
		callable $nodeCallback,
		ExpressionContext $context,
		?Type $passedToType
	): ExpressionResult
	{
		foreach ($expr->params as $param) {
			$this->processParamNode($param, $scope, $nodeCallback);
		}

		$byRefUses = [];

		if ($passedToType !== null && !$passedToType->isCallable()->no()) {
			$callableParameters = null;
			$acceptors = $passedToType->getCallableParametersAcceptors($scope);
			if (count($acceptors) === 1) {
				$callableParameters = $acceptors[0]->getParameters();
			}
		} else {
			$callableParameters = null;
		}

		$useScope = $scope;
		foreach ($expr->uses as $use) {
			if ($use->byRef) {
				$byRefUses[] = $use;
				$useScope = $useScope->enterExpressionAssign($use->var);

				$inAssignRightSideVariableName = $context->getInAssignRightSideVariableName();
				$inAssignRightSideType = $context->getInAssignRightSideType();
				if (
					$inAssignRightSideVariableName === $use->var->name
					&& $inAssignRightSideType !== null
				) {
					if ($inAssignRightSideType instanceof ClosureType) {
						$variableType = $inAssignRightSideType;
					} else {
						$alreadyHasVariableType = $scope->hasVariableType($inAssignRightSideVariableName);
						if ($alreadyHasVariableType->no()) {
							$variableType = TypeCombinator::union(new NullType(), $inAssignRightSideType);
						} else {
							$variableType = TypeCombinator::union($scope->getVariableType($inAssignRightSideVariableName), $inAssignRightSideType);
						}
					}
					$scope = $scope->assignVariable($inAssignRightSideVariableName, $variableType);
				}
			}
			$this->processExprNode($use, $useScope, $nodeCallback, $context);
			if (!$use->byRef) {
				continue;
			}

			$useScope = $useScope->exitExpressionAssign($use->var);
		}

		if ($expr->returnType !== null) {
			$nodeCallback($expr->returnType, $scope);
		}

		$closureScope = $scope->enterAnonymousFunction($expr, $callableParameters);
		$closureScope = $closureScope->processClosureScope($scope, null, $byRefUses);
		$nodeCallback(new InClosureNode($expr), $closureScope);

		$gatheredReturnStatements = [];
		$gatheredYieldStatements = [];
		$closureStmtsCallback = static function (\PhpParser\Node $node, Scope $scope) use ($nodeCallback, &$gatheredReturnStatements, &$gatheredYieldStatements, &$closureScope): void {
			$nodeCallback($node, $scope);
			if ($scope->getAnonymousFunctionReflection() !== $closureScope->getAnonymousFunctionReflection()) {
				return;
			}
			if ($node instanceof Expr\Yield_ || $node instanceof Expr\YieldFrom) {
				$gatheredYieldStatements[] = $node;
			}
			if (!$node instanceof Return_) {
				return;
			}

			$gatheredReturnStatements[] = new ReturnStatement($scope, $node);
		};
		if (count($byRefUses) === 0) {
			$statementResult = $this->processStmtNodes($expr, $expr->stmts, $closureScope, $closureStmtsCallback);
			$nodeCallback(new ClosureReturnStatementsNode(
				$expr,
				$gatheredReturnStatements,
				$gatheredYieldStatements,
				$statementResult
			), $closureScope);

			return new ExpressionResult($scope, false);
		}

		$count = 0;
		do {
			$prevScope = $closureScope;

			$intermediaryClosureScopeResult = $this->processStmtNodes($expr, $expr->stmts, $closureScope, static function (): void {
			});
			$intermediaryClosureScope = $intermediaryClosureScopeResult->getScope();
			foreach ($intermediaryClosureScopeResult->getExitPoints() as $exitPoint) {
				$intermediaryClosureScope = $intermediaryClosureScope->mergeWith($exitPoint->getScope());
			}
			$closureScope = $scope->enterAnonymousFunction($expr, $callableParameters);
			$closureScope = $closureScope->processClosureScope($intermediaryClosureScope, $prevScope, $byRefUses);
			if ($closureScope->equals($prevScope)) {
				break;
			}
			$count++;
		} while ($count < self::LOOP_SCOPE_ITERATIONS);

		$statementResult = $this->processStmtNodes($expr, $expr->stmts, $closureScope, $closureStmtsCallback);
		$nodeCallback(new ClosureReturnStatementsNode(
			$expr,
			$gatheredReturnStatements,
			$gatheredYieldStatements,
			$statementResult
		), $closureScope);

		return new ExpressionResult($scope->processClosureScope($closureScope, null, $byRefUses), false);
	}

	private function lookForArrayDestructuringArray(MutatingScope $scope, Expr $expr, Type $valueType): MutatingScope
	{
		if ($expr instanceof Array_ || $expr instanceof List_) {
			foreach ($expr->items as $key => $item) {
				/** @var \PhpParser\Node\Expr\ArrayItem|null $itemValue */
				$itemValue = $item;
				if ($itemValue === null) {
					continue;
				}

				$keyType = $itemValue->key === null ? new ConstantIntegerType($key) : $scope->getType($itemValue->key);
				$scope = $this->specifyItemFromArrayDestructuring($scope, $itemValue, $valueType, $keyType);
			}
		} elseif ($expr instanceof Variable && is_string($expr->name)) {
			$scope = $scope->assignVariable($expr->name, new MixedType());
		} elseif ($expr instanceof ArrayDimFetch && $expr->var instanceof Variable && is_string($expr->var->name)) {
			$scope = $scope->assignVariable($expr->var->name, new MixedType());
		}

		return $scope;
	}

	private function specifyItemFromArrayDestructuring(MutatingScope $scope, ArrayItem $arrayItem, Type $valueType, Type $keyType): MutatingScope
	{
		$type = $valueType->getOffsetValueType($keyType);

		$itemNode = $arrayItem->value;
		if ($itemNode instanceof Variable && is_string($itemNode->name)) {
			$scope = $scope->assignVariable($itemNode->name, $type);
		} elseif ($itemNode instanceof ArrayDimFetch && $itemNode->var instanceof Variable && is_string($itemNode->var->name)) {
			$currentType = $scope->hasVariableType($itemNode->var->name)->no()
				? new ConstantArrayType([], [])
				: $scope->getVariableType($itemNode->var->name);
			$dimType = null;
			if ($itemNode->dim !== null) {
				$dimType = $scope->getType($itemNode->dim);
			}
			$scope = $scope->assignVariable($itemNode->var->name, $currentType->setOffsetValueType($dimType, $type));
		} else {
			$scope = $this->lookForArrayDestructuringArray($scope, $itemNode, $type);
		}

		return $scope;
	}

	/**
	 * @param \PhpParser\Node\Param $param
	 * @param \PHPStan\Analyser\MutatingScope $scope
	 * @param callable(\PhpParser\Node $node, Scope $scope): void $nodeCallback
	 */
	private function processParamNode(
		Node\Param $param,
		MutatingScope $scope,
		callable $nodeCallback
	): void
	{
		foreach ($param->attrGroups as $attrGroup) {
			foreach ($attrGroup->attrs as $attr) {
				foreach ($attr->args as $arg) {
					$nodeCallback($arg->value, $scope);
				}
			}
		}
		$nodeCallback($param, $scope);
		if ($param->type !== null) {
			$nodeCallback($param->type, $scope);
		}
		if ($param->default === null) {
			return;
		}

		$this->processExprNode($param->default, $scope, $nodeCallback, ExpressionContext::createDeep());
	}

	/**
	 * @param \PHPStan\Reflection\MethodReflection|\PHPStan\Reflection\FunctionReflection|null $calleeReflection
	 * @param ParametersAcceptor|null $parametersAcceptor
	 * @param \PhpParser\Node\Arg[] $args
	 * @param \PHPStan\Analyser\MutatingScope $scope
	 * @param callable(\PhpParser\Node $node, Scope $scope): void $nodeCallback
	 * @param ExpressionContext $context
	 * @param \PHPStan\Analyser\MutatingScope|null $closureBindScope
	 * @return \PHPStan\Analyser\ExpressionResult
	 */
	private function processArgs(
		$calleeReflection,
		?ParametersAcceptor $parametersAcceptor,
		array $args,
		MutatingScope $scope,
		callable $nodeCallback,
		ExpressionContext $context,
		?MutatingScope $closureBindScope = null
	): ExpressionResult
	{
		if ($parametersAcceptor !== null) {
			$parameters = $parametersAcceptor->getParameters();
		}

		if ($calleeReflection !== null) {
			$scope = $scope->pushInFunctionCall($calleeReflection);
		}

		$hasYield = false;
		foreach ($args as $i => $arg) {
			$nodeCallback($arg, $scope);
			if (isset($parameters) && $parametersAcceptor !== null) {
				$assignByReference = false;
				if (isset($parameters[$i])) {
					$assignByReference = $parameters[$i]->passedByReference()->createsNewVariable();
					$parameterType = $parameters[$i]->getType();
				} elseif (count($parameters) > 0 && $parametersAcceptor->isVariadic()) {
					$lastParameter = $parameters[count($parameters) - 1];
					$assignByReference = $lastParameter->passedByReference()->createsNewVariable();
					$parameterType = $lastParameter->getType();
				}

				if ($assignByReference) {
					$argValue = $arg->value;
					if ($argValue instanceof Variable && is_string($argValue->name)) {
						$scope = $scope->assignVariable($argValue->name, new MixedType());
					}
				}

				if ($calleeReflection instanceof FunctionReflection) {
					if (
						$i === 0
						&& $calleeReflection->getName() === 'array_map'
						&& isset($args[1])
					) {
						$parameterType = new CallableType([
							new DummyParameter('item', $scope->getType($args[1]->value)->getIterableValueType(), false, PassedByReference::createNo(), false, null),
						], new MixedType(), false);
					}

					if (
						$i === 1
						&& $calleeReflection->getName() === 'array_filter'
						&& isset($args[0])
					) {
						if (isset($args[2])) {
							$mode = $scope->getType($args[2]->value);
							if ($mode instanceof ConstantIntegerType) {
								if ($mode->getValue() === ARRAY_FILTER_USE_KEY) {
									$arrayFilterParameters = [
										new DummyParameter('key', $scope->getType($args[0]->value)->getIterableKeyType(), false, PassedByReference::createNo(), false, null),
									];
								} elseif ($mode->getValue() === ARRAY_FILTER_USE_BOTH) {
									$arrayFilterParameters = [
										new DummyParameter('item', $scope->getType($args[0]->value)->getIterableValueType(), false, PassedByReference::createNo(), false, null),
										new DummyParameter('key', $scope->getType($args[0]->value)->getIterableKeyType(), false, PassedByReference::createNo(), false, null),
									];
								}
							}
						}
						$parameterType = new CallableType(
							$arrayFilterParameters ?? [
								new DummyParameter('item', $scope->getType($args[0]->value)->getIterableValueType(), false, PassedByReference::createNo(), false, null),
							],
							new MixedType(),
							false
						);
					}
				}
			}

			$originalScope = $scope;
			$scopeToPass = $scope;
			if ($i === 0 && $closureBindScope !== null) {
				$scopeToPass = $closureBindScope;
			}

			if ($arg->value instanceof Expr\Closure) {
				$this->callNodeCallbackWithExpression($nodeCallback, $arg->value, $scopeToPass, $context);
				$result = $this->processClosureNode($arg->value, $scopeToPass, $nodeCallback, $context, $parameterType ?? null);
			} else {
				$result = $this->processExprNode($arg->value, $scopeToPass, $nodeCallback, $context->enterDeep());
			}
			$scope = $result->getScope();
			$hasYield = $hasYield || $result->hasYield();
			if ($i !== 0 || $closureBindScope === null) {
				continue;
			}

			$scope = $scope->restoreOriginalScopeAfterClosureBind($originalScope);
		}

		if ($calleeReflection !== null) {
			$scope = $scope->popInFunctionCall();
		}

		return new ExpressionResult($scope, $hasYield);
	}

	/**
	 * @param \PHPStan\Analyser\MutatingScope $scope
	 * @param \PhpParser\Node\Expr $var
	 * @param \PhpParser\Node\Expr $assignedExpr
	 * @param callable(\PhpParser\Node $node, Scope $scope): void $nodeCallback
	 * @param ExpressionContext $context
	 * @param \Closure(MutatingScope $scope): ExpressionResult $processExprCallback
	 * @param bool $enterExpressionAssign
	 * @return ExpressionResult
	 */
	private function processAssignVar(
		MutatingScope $scope,
		Expr $var,
		Expr $assignedExpr,
		callable $nodeCallback,
		ExpressionContext $context,
		\Closure $processExprCallback,
		bool $enterExpressionAssign
	): ExpressionResult
	{
		$nodeCallback($var, $enterExpressionAssign ? $scope->enterExpressionAssign($var) : $scope);
		$hasYield = false;
		if ($var instanceof Variable && is_string($var->name)) {
			$result = $processExprCallback($scope);
			$hasYield = $result->hasYield();
			$type = $scope->getType($assignedExpr);
			$scope = $result->getScope()->assignVariable($var->name, $type);

			if ($type instanceof BooleanType) {
				$truthyScope = $scope->filterByTruthyValue($assignedExpr)->assignVariable($var->name, TypeCombinator::remove($type, StaticTypeFactory::falsey()));
				$falseyScope = $scope->filterByFalseyValue($assignedExpr)->assignVariable($var->name, TypeCombinator::intersect($type, StaticTypeFactory::falsey()));
				$scope = $truthyScope->mergeWith($falseyScope);
			}
		} elseif ($var instanceof ArrayDimFetch) {
			$dimExprStack = [];
			$originalVar = $var;
			while ($var instanceof ArrayDimFetch) {
				$dimExprStack[] = $var->dim;
				$var = $var->var;
			}

			// 1. eval root expr
			if ($enterExpressionAssign && $var instanceof Variable) {
				$scope = $scope->enterExpressionAssign($var);
			}
			$result = $this->processExprNode($var, $scope, $nodeCallback, $context->enterDeep());
			$hasYield = $result->hasYield();
			$scope = $result->getScope();
			if ($enterExpressionAssign && $var instanceof Variable) {
				$scope = $scope->exitExpressionAssign($var);
			}

			// 2. eval dimensions
			$offsetTypes = [];
			foreach (array_reverse($dimExprStack) as $dimExpr) {
				if ($dimExpr === null) {
					$offsetTypes[] = null;

				} else {
					$offsetTypes[] = $scope->getType($dimExpr);

					if ($enterExpressionAssign) {
						$scope->enterExpressionAssign($dimExpr);
					}
					$result = $this->processExprNode($dimExpr, $scope, $nodeCallback, $context->enterDeep());
					$hasYield = $hasYield || $result->hasYield();
					$scope = $result->getScope();

					if ($enterExpressionAssign) {
						$scope = $scope->exitExpressionAssign($dimExpr);
					}
				}
			}

			$valueToWrite = $scope->getType($assignedExpr);
			$originalValueToWrite = $valueToWrite;

			// 3. eval assigned expr
			$result = $processExprCallback($scope);
			$hasYield = $hasYield || $result->hasYield();
			$scope = $result->getScope();

			$varType = $scope->getType($var);
			if (!(new ObjectType(\ArrayAccess::class))->isSuperTypeOf($varType)->yes()) {
				// 4. compose types
				if ($varType instanceof ErrorType) {
					$varType = new ConstantArrayType([], []);
				}
				$offsetValueType = $varType;
				$offsetValueTypeStack = [$offsetValueType];
				foreach (array_slice($offsetTypes, 0, -1) as $offsetType) {
					if ($offsetType === null) {
						$offsetValueType = new ConstantArrayType([], []);

					} else {
						$offsetValueType = $offsetValueType->getOffsetValueType($offsetType);
						if ($offsetValueType instanceof ErrorType) {
							$offsetValueType = new ConstantArrayType([], []);
						}
					}

					$offsetValueTypeStack[] = $offsetValueType;
				}

				foreach (array_reverse($offsetTypes) as $i => $offsetType) {
					/** @var Type $offsetValueType */
					$offsetValueType = array_pop($offsetValueTypeStack);

					/** @phpstan-ignore-next-line */
					$valueToWrite = $offsetValueType->setOffsetValueType($offsetType, $valueToWrite, $i === 0);
				}

				if ($var instanceof Variable && is_string($var->name)) {
					$scope = $scope->assignVariable($var->name, $valueToWrite);
				} else {
					$scope = $scope->assignExpression(
						$var,
						$valueToWrite
					);
				}

				if ($originalVar->dim instanceof Variable || $originalVar->dim instanceof Node\Scalar) {
					$currentVarType = $scope->getType($originalVar);
					if (!$originalValueToWrite->isSuperTypeOf($currentVarType)->yes()) {
						$scope = $scope->assignExpression(
							$originalVar,
							$originalValueToWrite
						);
					}
				}
			}
		} elseif ($var instanceof PropertyFetch) {
			$this->processExprNode($var->var, $scope, $nodeCallback, $context);
			$result = $processExprCallback($scope);
			$hasYield = $result->hasYield();
			$scope = $result->getScope();

			$propertyHolderType = $scope->getType($var->var);
			$propertyName = null;
			if ($var->name instanceof Node\Identifier) {
				$propertyName = $var->name->name;
			}
			if ($propertyName !== null && $propertyHolderType->hasProperty($propertyName)->yes()) {
				$propertyReflection = $propertyHolderType->getProperty($propertyName, $scope);
				if ($propertyReflection->canChangeTypeAfterAssignment()) {
					$scope = $scope->assignExpression($var, $scope->getType($assignedExpr));
				}
			} else {
				// fallback
				$scope = $scope->assignExpression($var, $scope->getType($assignedExpr));
			}

		} elseif ($var instanceof Expr\StaticPropertyFetch) {
			if ($var->class instanceof \PhpParser\Node\Name) {
				$propertyHolderType = $scope->resolveTypeByName($var->class);
			} else {
				$this->processExprNode($var->class, $scope, $nodeCallback, $context);
				$propertyHolderType = $scope->getType($var->class);
			}

			$result = $processExprCallback($scope);
			$hasYield = $result->hasYield();
			$scope = $result->getScope();

			$propertyName = null;
			if ($var->name instanceof Node\Identifier) {
				$propertyName = $var->name->name;
			}
			if ($propertyName !== null && $propertyHolderType->hasProperty($propertyName)->yes()) {
				$propertyReflection = $propertyHolderType->getProperty($propertyName, $scope);
				if ($propertyReflection->canChangeTypeAfterAssignment()) {
					$scope = $scope->assignExpression($var, $scope->getType($assignedExpr));
				}
			} else {
				// fallback
				$scope = $scope->assignExpression($var, $scope->getType($assignedExpr));
			}
		}

		return new ExpressionResult($scope, $hasYield);
	}

	private function processStmtVarAnnotation(MutatingScope $scope, Node\Stmt $stmt, ?Expr $defaultExpr): MutatingScope
	{
		$function = $scope->getFunction();
		$variableLessTags = [];

		foreach ($stmt->getComments() as $comment) {
			if (!$comment instanceof Doc) {
				continue;
			}

			$resolvedPhpDoc = $this->fileTypeMapper->getResolvedPhpDoc(
				$scope->getFile(),
				$scope->isInClass() ? $scope->getClassReflection()->getName() : null,
				$scope->isInTrait() ? $scope->getTraitReflection()->getName() : null,
				$function !== null ? $function->getName() : null,
				$comment->getText()
			);

			$assignedVariable = null;
			if (
				$stmt instanceof Node\Stmt\Expression
				&& ($stmt->expr instanceof Assign || $stmt->expr instanceof AssignRef)
				&& $stmt->expr->var instanceof Variable
				&& is_string($stmt->expr->var->name)
			) {
				$assignedVariable = $stmt->expr->var->name;
			}

			foreach ($resolvedPhpDoc->getVarTags() as $name => $varTag) {
				if (is_int($name)) {
					$variableLessTags[] = $varTag;
					continue;
				}

				if ($name === $assignedVariable) {
					continue;
				}

				$certainty = $scope->hasVariableType($name);
				if ($certainty->no()) {
					continue;
				}

				if ($scope->isInClass() && $scope->getFunction() === null) {
					continue;
				}

				if ($scope->canAnyVariableExist()) {
					$certainty = TrinaryLogic::createYes();
				}

				$scope = $scope->assignVariable($name, $varTag->getType(), $certainty);
			}
		}

		if (count($variableLessTags) === 1 && $defaultExpr !== null) {
			$scope = $scope->specifyExpressionType($defaultExpr, $variableLessTags[0]->getType());
		}

		return $scope;
	}

	/**
	 * @param MutatingScope $scope
	 * @param array<int, string> $variableNames
	 * @param Node $node
	 * @param bool $changed
	 * @return MutatingScope
	 */
	private function processVarAnnotation(MutatingScope $scope, array $variableNames, Node $node, bool &$changed = false): MutatingScope
	{
		$function = $scope->getFunction();
		$varTags = [];
		foreach ($node->getComments() as $comment) {
			if (!$comment instanceof Doc) {
				continue;
			}

			$resolvedPhpDoc = $this->fileTypeMapper->getResolvedPhpDoc(
				$scope->getFile(),
				$scope->isInClass() ? $scope->getClassReflection()->getName() : null,
				$scope->isInTrait() ? $scope->getTraitReflection()->getName() : null,
				$function !== null ? $function->getName() : null,
				$comment->getText()
			);
			foreach ($resolvedPhpDoc->getVarTags() as $key => $varTag) {
				$varTags[$key] = $varTag;
			}
		}

		if (count($varTags) === 0) {
			return $scope;
		}

		foreach ($variableNames as $variableName) {
			if (!isset($varTags[$variableName])) {
				continue;
			}

			$variableType = $varTags[$variableName]->getType();
			$changed = true;
			$scope = $scope->assignVariable($variableName, $variableType);
		}

		if (count($variableNames) === 1 && count($varTags) === 1 && isset($varTags[0])) {
			$variableType = $varTags[0]->getType();
			$changed = true;
			$scope = $scope->assignVariable($variableNames[0], $variableType);
		}

		return $scope;
	}

	private function enterForeach(MutatingScope $scope, Foreach_ $stmt): MutatingScope
	{
		if ($stmt->expr instanceof Variable && is_string($stmt->expr->name)) {
			$scope = $this->processVarAnnotation($scope, [$stmt->expr->name], $stmt);
		}
		$iterateeType = $scope->getType($stmt->expr);
		$vars = [];
		if ($stmt->valueVar instanceof Variable && is_string($stmt->valueVar->name)) {
			$scope = $scope->enterForeach(
				$stmt->expr,
				$stmt->valueVar->name,
				$stmt->keyVar !== null
				&& $stmt->keyVar instanceof Variable
				&& is_string($stmt->keyVar->name)
					? $stmt->keyVar->name
					: null
			);
			$vars[] = $stmt->valueVar->name;
		}

		if (
			$stmt->keyVar instanceof Variable && is_string($stmt->keyVar->name)
		) {
			$scope = $scope->enterForeachKey($stmt->expr, $stmt->keyVar->name);
			$vars[] = $stmt->keyVar->name;
		}

		if (
			$stmt->getDocComment() === null
			&& $iterateeType instanceof ConstantArrayType
			&& $stmt->valueVar instanceof Variable && is_string($stmt->valueVar->name)
			&& $stmt->keyVar instanceof Variable && is_string($stmt->keyVar->name)
		) {
			$conditionalHolders = [];
			foreach ($iterateeType->getKeyTypes() as $i => $keyType) {
				$valueType = $iterateeType->getValueTypes()[$i];
				$conditionalHolders[] = new ConditionalExpressionHolder([
					'$' . $stmt->keyVar->name => $keyType,
				], new VariableTypeHolder($valueType, TrinaryLogic::createYes()));
			}

			$scope = $scope->addConditionalExpressions(
				'$' . $stmt->valueVar->name,
				$conditionalHolders
			);
		}

		if ($stmt->valueVar instanceof List_ || $stmt->valueVar instanceof Array_) {
			$exprType = $scope->getType($stmt->expr);
			$itemType = $exprType->getIterableValueType();
			$scope = $this->lookForArrayDestructuringArray($scope, $stmt->valueVar, $itemType);
			$vars = array_merge($vars, $this->getAssignedVariables($stmt->valueVar));
		}

		$scope = $this->processVarAnnotation($scope, $vars, $stmt);

		return $scope;
	}

	/**
	 * @param \PhpParser\Node\Stmt\TraitUse $node
	 * @param MutatingScope $classScope
	 * @param callable(\PhpParser\Node $node, Scope $scope): void $nodeCallback
	 */
	private function processTraitUse(Node\Stmt\TraitUse $node, MutatingScope $classScope, callable $nodeCallback): void
	{
		foreach ($node->traits as $trait) {
			$traitName = (string) $trait;
			if (!$this->reflectionProvider->hasClass($traitName)) {
				continue;
			}
			$traitReflection = $this->reflectionProvider->getClass($traitName);
			$traitFileName = $traitReflection->getFileName();
			if ($traitFileName === false) {
				continue; // trait from eval or from PHP itself
			}
			$fileName = $this->fileHelper->normalizePath($traitFileName);
			if (!isset($this->analysedFiles[$fileName])) {
				continue;
			}
			$parserNodes = $this->parser->parseFile($fileName);
			$this->processNodesForTraitUse($parserNodes, $traitReflection, $classScope, $nodeCallback);
		}
	}

	/**
	 * @param \PhpParser\Node[]|\PhpParser\Node|scalar $node
	 * @param ClassReflection $traitReflection
	 * @param \PHPStan\Analyser\MutatingScope $scope
	 * @param callable(\PhpParser\Node $node, Scope $scope): void $nodeCallback
	 */
	private function processNodesForTraitUse($node, ClassReflection $traitReflection, MutatingScope $scope, callable $nodeCallback): void
	{
		if ($node instanceof Node) {
			if ($node instanceof Node\Stmt\Trait_ && $traitReflection->getName() === (string) $node->namespacedName && $traitReflection->getNativeReflection()->getStartLine() === $node->getStartLine()) {
				$this->processStmtNodes($node, $node->stmts, $scope->enterTrait($traitReflection), $nodeCallback);
				return;
			}
			if ($node instanceof Node\Stmt\ClassLike) {
				return;
			}
			if ($node instanceof Node\FunctionLike) {
				return;
			}
			foreach ($node->getSubNodeNames() as $subNodeName) {
				$subNode = $node->{$subNodeName};
				$this->processNodesForTraitUse($subNode, $traitReflection, $scope, $nodeCallback);
			}
		} elseif (is_array($node)) {
			foreach ($node as $subNode) {
				$this->processNodesForTraitUse($subNode, $traitReflection, $scope, $nodeCallback);
			}
		}
	}

	/**
	 * @param Scope $scope
	 * @param Node\FunctionLike $functionLike
	 * @return array{TemplateTypeMap, Type[], ?Type, ?Type, ?string, bool, bool, bool, bool|null}
	 */
	public function getPhpDocs(Scope $scope, Node\FunctionLike $functionLike): array
	{
		$templateTypeMap = TemplateTypeMap::createEmpty();
		$phpDocParameterTypes = [];
		$phpDocReturnType = null;
		$phpDocThrowType = null;
		$deprecatedDescription = null;
		$isDeprecated = false;
		$isInternal = false;
		$isFinal = false;
		$isPure = false;
		$docComment = $functionLike->getDocComment() !== null
			? $functionLike->getDocComment()->getText()
			: null;

		$file = $scope->getFile();
		$class = $scope->isInClass() ? $scope->getClassReflection()->getName() : null;
		$trait = $scope->isInTrait() ? $scope->getTraitReflection()->getName() : null;
		$resolvedPhpDoc = null;
		$functionName = null;

		if ($functionLike instanceof Node\Stmt\ClassMethod) {
			if (!$scope->isInClass()) {
				throw new \PHPStan\ShouldNotHappenException();
			}
			$functionName = $functionLike->name->name;
			$positionalParameterNames = array_map(static function (Node\Param $param): string {
				if (!$param->var instanceof Variable || !is_string($param->var->name)) {
					throw new \PHPStan\ShouldNotHappenException();
				}

				return $param->var->name;
			}, $functionLike->getParams());
			$resolvedPhpDoc = $this->phpDocInheritanceResolver->resolvePhpDocForMethod(
				$docComment,
				$file,
				$scope->getClassReflection(),
				$trait,
				$functionLike->name->name,
				$positionalParameterNames
			);

			if ($functionLike->name->toLowerString() === '__construct') {
				foreach ($functionLike->params as $param) {
					if ($param->flags === 0) {
						continue;
					}

					if ($param->getDocComment() === null) {
						continue;
					}

					if (
						!$param->var instanceof Variable
						|| !is_string($param->var->name)
					) {
						throw new \PHPStan\ShouldNotHappenException();
					}

					$paramPhpDoc = $this->fileTypeMapper->getResolvedPhpDoc(
						$file,
						$class,
						$trait,
						'__construct',
						$param->getDocComment()->getText()
					);
					$varTags = $paramPhpDoc->getVarTags();
					if (isset($varTags[0]) && count($varTags) === 1) {
						$phpDocType = $varTags[0]->getType();
					} elseif (isset($varTags[$param->var->name])) {
						$phpDocType = $varTags[$param->var->name]->getType();
					} else {
						continue;
					}

					$phpDocParameterTypes[$param->var->name] = $phpDocType;
				}
			}
		} elseif ($functionLike instanceof Node\Stmt\Function_) {
			$functionName = trim($scope->getNamespace() . '\\' . $functionLike->name->name, '\\');
		}

		if ($docComment !== null && $resolvedPhpDoc === null) {
			$resolvedPhpDoc = $this->fileTypeMapper->getResolvedPhpDoc(
				$file,
				$class,
				$trait,
				$functionName,
				$docComment
			);
		}

		if ($resolvedPhpDoc !== null) {
			$templateTypeMap = $resolvedPhpDoc->getTemplateTypeMap();
			foreach ($resolvedPhpDoc->getParamTags() as $paramName => $paramTag) {
				if (array_key_exists($paramName, $phpDocParameterTypes)) {
					continue;
				}
				$phpDocParameterTypes[$paramName] = $paramTag->getType();
			}
			$nativeReturnType = $scope->getFunctionType($functionLike->getReturnType(), false, false);
			$phpDocReturnType = $this->getPhpDocReturnType($resolvedPhpDoc, $nativeReturnType);
			$phpDocThrowType = $resolvedPhpDoc->getThrowsTag() !== null ? $resolvedPhpDoc->getThrowsTag()->getType() : null;
			$deprecatedDescription = $resolvedPhpDoc->getDeprecatedTag() !== null ? $resolvedPhpDoc->getDeprecatedTag()->getMessage() : null;
			$isDeprecated = $resolvedPhpDoc->isDeprecated();
			$isInternal = $resolvedPhpDoc->isInternal();
			$isFinal = $resolvedPhpDoc->isFinal();
			$isPure = $resolvedPhpDoc->isPure();
		}

		return [$templateTypeMap, $phpDocParameterTypes, $phpDocReturnType, $phpDocThrowType, $deprecatedDescription, $isDeprecated, $isInternal, $isFinal, $isPure];
	}

	private function getPhpDocReturnType(ResolvedPhpDocBlock $resolvedPhpDoc, Type $nativeReturnType): ?Type
	{
		$returnTag = $resolvedPhpDoc->getReturnTag();

		if ($returnTag === null) {
			return null;
		}

		$phpDocReturnType = $returnTag->getType();

		if ($returnTag->isExplicit()) {
			return $phpDocReturnType;
		}

		if ($nativeReturnType->isSuperTypeOf(TemplateTypeHelper::resolveToBounds($phpDocReturnType))->yes()) {
			return $phpDocReturnType;
		}

		return null;
	}

}

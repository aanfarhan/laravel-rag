# Contributing to Laravel RAG

Thank you for considering contributing to the Laravel RAG package! This document provides guidelines and information for contributors.

## Table of Contents

- [Code of Conduct](#code-of-conduct)
- [Getting Started](#getting-started)
- [Development Setup](#development-setup)
- [Making Changes](#making-changes)
- [Testing](#testing)
- [Submitting Changes](#submitting-changes)
- [Coding Standards](#coding-standards)
- [Documentation](#documentation)

## Code of Conduct

This project adheres to a code of conduct. By participating, you are expected to uphold this code. Please report unacceptable behavior to the project maintainers.

### Our Standards

- Be respectful and inclusive
- Welcome newcomers and help them get started
- Focus on constructive feedback
- Acknowledge different viewpoints and experiences
- Show empathy towards other community members

## Getting Started

### Types of Contributions

We welcome several types of contributions:

- **Bug Reports**: Help us identify and fix issues
- **Feature Requests**: Suggest new functionality
- **Code Contributions**: Submit bug fixes and new features
- **Documentation**: Improve existing docs or add new ones
- **Testing**: Add test coverage or improve existing tests

### Before You Start

1. Check existing [issues](https://github.com/omniglies/laravel-rag/issues) and [pull requests](https://github.com/omniglies/laravel-rag/pulls)
2. For major changes, open an issue first to discuss the approach
3. Ensure you understand the project's architecture and goals

## Development Setup

### Prerequisites

- PHP 8.1+
- Composer
- Node.js & NPM (for frontend assets)
- Docker (optional, for testing different environments)

### Fork and Clone

```bash
# Fork the repository on GitHub, then clone your fork
git clone https://github.com/your-username/laravel-rag.git
cd laravel-rag

# Add the original repository as upstream
git remote add upstream https://github.com/omniglies/laravel-rag.git
```

### Install Dependencies

```bash
# Install PHP dependencies
composer install

# Install JavaScript dependencies
npm install

# Set up testing environment
cp .env.example .env.testing
```

### Set Up Test Environment

```bash
# Create test database
touch database/testing.sqlite

# Run migrations for testing
php artisan migrate --env=testing

# Set up test configuration
export RAG_EXTERNAL_PROCESSING_ENABLED=false
export RAG_ROUTES_ENABLED=true
```

## Making Changes

### Branching Strategy

- Create feature branches from `main`
- Use descriptive branch names: `feature/vector-search-optimization`, `fix/document-upload-validation`
- Keep branches focused on a single feature or fix

```bash
# Create and switch to a new branch
git checkout -b feature/your-feature-name

# Keep your branch up to date with upstream
git fetch upstream
git rebase upstream/main
```

### Commit Guidelines

We follow [Conventional Commits](https://www.conventionalcommits.org/):

```bash
# Format: type(scope): description
git commit -m "feat(vector-search): add support for Qdrant vector database"
git commit -m "fix(upload): validate file size before processing"
git commit -m "docs(readme): update installation instructions"
```

#### Commit Types

- `feat`: New features
- `fix`: Bug fixes
- `docs`: Documentation changes
- `style`: Code style changes (formatting, etc.)
- `refactor`: Code refactoring
- `test`: Adding or updating tests
- `chore`: Maintenance tasks

### Code Organization

#### Adding New AI Providers

1. Create a new provider class in `src/Services/AiProviders/`
2. Implement the `AiProviderInterface`
3. Add configuration options to `config/rag.php`
4. Update the service provider binding logic
5. Add tests for the new provider

```php
// Example: src/Services/AiProviders/NewAiProvider.php
namespace Omniglies\LaravelRag\Services\AiProviders;

class NewAiProvider implements AiProviderInterface
{
    public function generateResponse(string $prompt, array $options = []): array
    {
        // Implementation
    }
    
    // Other required methods...
}
```

#### Adding New Vector Database Providers

1. Add provider-specific methods to `VectorSearchService`
2. Update the `initializeClient()` method
3. Add configuration to `config/rag.php`
4. Implement the required CRUD operations
5. Add comprehensive tests

#### Adding New Console Commands

1. Create command class in `src/Console/Commands/`
2. Register in `RagServiceProvider`
3. Follow Laravel's command conventions
4. Add help text and examples

### Frontend Development

The package uses Alpine.js for frontend interactivity:

#### Component Development

```javascript
// Add new Alpine.js components to resources/assets/js/rag-alpine.js
function newRagComponent() {
    return {
        // Component data and methods
        init() {
            // Initialization logic
        }
    };
}

// Register globally
window.newRagComponent = newRagComponent;
```

#### Styling

- Use the existing CSS custom properties in `resources/assets/css/rag.css`
- Follow the established naming convention (`rag-*`)
- Ensure responsive design
- Test across different browsers

## Testing

### Running Tests

```bash
# Run all tests
composer test

# Run specific test suites
vendor/bin/phpunit tests/Unit
vendor/bin/phpunit tests/Feature

# Run with coverage
vendor/bin/phpunit --coverage-html coverage
```

### Writing Tests

#### Unit Tests

Test individual components in isolation:

```php
namespace Omniglies\LaravelRag\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Omniglies\LaravelRag\Services\YourService;

class YourServiceTest extends TestCase
{
    public function test_method_returns_expected_result()
    {
        // Arrange
        $service = new YourService();
        
        // Act
        $result = $service->yourMethod('input');
        
        // Assert
        $this->assertEquals('expected', $result);
    }
}
```

#### Feature Tests

Test complete workflows:

```php
namespace Omniglies\LaravelRag\Tests\Feature;

use Orchestra\Testbench\TestCase;
use Omniglies\LaravelRag\RagServiceProvider;

class YourFeatureTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [RagServiceProvider::class];
    }
    
    public function test_feature_works_end_to_end()
    {
        // Test HTTP endpoints, database interactions, etc.
    }
}
```

### Test Coverage

- Aim for >80% code coverage
- Focus on critical paths and edge cases
- Mock external API calls
- Test error scenarios

## Submitting Changes

### Pull Request Process

1. **Ensure tests pass**: Run the full test suite
2. **Update documentation**: Include relevant doc updates
3. **Add changelog entry**: Describe your changes
4. **Create pull request**: Use the provided template

### Pull Request Template

```markdown
## Description
Brief description of changes

## Type of Change
- [ ] Bug fix
- [ ] New feature
- [ ] Breaking change
- [ ] Documentation update

## Testing
- [ ] Tests pass locally
- [ ] New tests added for new functionality
- [ ] Manual testing completed

## Checklist
- [ ] Code follows style guidelines
- [ ] Self-review completed
- [ ] Documentation updated
- [ ] No breaking changes (or marked as such)
```

### Review Process

1. **Automated checks**: CI/CD pipeline runs tests and style checks
2. **Code review**: Maintainers review code and provide feedback
3. **Iterations**: Address feedback and push updates
4. **Approval**: Once approved, changes are merged

## Coding Standards

### PHP Standards

We follow [PSR-12](https://www.php-fig.org/psr/psr-12/) coding standards:

```php
<?php

namespace Omniglies\LaravelRag\Services;

class ExampleService
{
    public function __construct(
        private readonly SomeDependency $dependency
    ) {}

    public function performAction(string $input): array
    {
        // Method implementation
        return [];
    }
}
```

### Code Quality Tools

```bash
# PHP CS Fixer (code style)
composer fix-style

# PHPStan (static analysis)
composer analyse

# Combined quality check
composer quality
```

### Laravel Conventions

- Use Eloquent relationships appropriately
- Follow Laravel naming conventions
- Use type hints and return types
- Implement proper error handling

### Database Standards

- Use descriptive migration names
- Add proper indexes for performance
- Use foreign key constraints
- Follow Laravel migration conventions

```php
Schema::create('rag_knowledge_documents', function (Blueprint $table) {
    $table->id();
    $table->string('title');
    $table->enum('processing_status', ['pending', 'processing', 'completed', 'failed'])
          ->default('pending')
          ->index();
    $table->timestamps();
    
    $table->index(['processing_status', 'created_at']);
});
```

## Documentation

### Types of Documentation

1. **API Documentation**: Document public methods and classes
2. **User Documentation**: README and usage guides
3. **Configuration Documentation**: Explain all config options
4. **Architecture Documentation**: Explain design decisions

### Documentation Standards

#### Code Comments

```php
/**
 * Generate embeddings for the given text using the configured provider.
 *
 * @param string $text The text to generate embeddings for
 * @param array $options Additional options for embedding generation
 * @return array The generated embedding vector
 * @throws RagException When embedding generation fails
 */
public function generateEmbedding(string $text, array $options = []): array
{
    // Implementation
}
```

#### README Updates

- Keep installation instructions current
- Add examples for new features
- Update configuration documentation
- Include troubleshooting tips

#### Configuration Documentation

```php
// config/rag.php
return [
    // Vector database configuration
    'vector_database' => [
        // The vector database provider to use
        // Supported: 'pinecone', 'weaviate', 'qdrant'
        'provider' => env('RAG_VECTOR_PROVIDER', 'pinecone'),
        
        // Provider-specific configurations
        'providers' => [
            // ...
        ],
    ],
];
```

## Architecture Guidelines

### Service Layer

- Keep services focused on a single responsibility
- Use dependency injection
- Return consistent data structures
- Handle errors gracefully

### Database Layer

- Use Eloquent models for data access
- Implement proper relationships
- Use scopes for common queries
- Follow Laravel naming conventions

### API Layer

- Use form requests for validation
- Return consistent JSON responses
- Implement proper error handling
- Follow RESTful conventions

### Queue Integration

- Use jobs for long-running tasks
- Implement proper failure handling
- Add progress tracking
- Use appropriate queue priorities

## Getting Help

### Resources

- [Laravel Documentation](https://laravel.com/docs)
- [Package Documentation](https://docs.laravel-rag.com)
- [GitHub Issues](https://github.com/omniglies/laravel-rag/issues)
- [Discussions](https://github.com/omniglies/laravel-rag/discussions)

### Communication

- **Bug Reports**: Use GitHub Issues
- **Feature Requests**: Use GitHub Issues with feature template
- **Questions**: Use GitHub Discussions
- **Security Issues**: Email security@omniglies.com

### Debugging

```bash
# Enable debug mode
export APP_DEBUG=true
export LOG_LEVEL=debug

# Run with verbose output
php artisan rag:status --test-config -v

# Check logs
tail -f storage/logs/laravel.log
```

## Recognition

Contributors will be recognized in:

- GitHub contributors list
- CHANGELOG.md for significant contributions
- README.md credits section
- Release notes for major contributions

Thank you for contributing to Laravel RAG! 🚀
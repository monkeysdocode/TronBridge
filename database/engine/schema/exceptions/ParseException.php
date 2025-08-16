<?php

require_once __DIR__ . '/TranslationException.php';

/**
 * Parse Exception
 * 
 * Thrown when SQL parsing fails.
 * 
 * @package Database\Exceptions
 * @author Enhanced Model System
 * @version 1.0.0
 */
class ParseException extends TranslationException
{
    protected ?int $parseLine = null;
    protected ?int $parsePosition = null;
    protected ?string $nearText = null;
    
    public function __construct(
        string $message,
        ?int $line = null,
        ?int $position = null,
        ?string $nearText = null
    ) {
        $this->parseLine = $line;
        $this->parsePosition = $position;
        $this->nearText = $nearText;
        
        $context = [];
        if ($line !== null) {
            $context['line'] = $line;
            $message .= " at line $line";
        }
        if ($position !== null) {
            $context['position'] = $position;
            $message .= " position $position";
        }
        if ($nearText !== null) {
            $context['near'] = $nearText;
            $message .= " near '$nearText'";
        }
        
        parent::__construct($message, 0, null, $context);
    }
    
    public function getParseLine(): ?int
    {
        return $this->parseLine;
    }
    
    public function getParsePosition(): ?int
    {
        return $this->parsePosition;
    }
    
    public function getNearText(): ?string
    {
        return $this->nearText;
    }
}
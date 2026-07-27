<?php

namespace App\Enums;

enum MessageReactionType: string
{
    case ThumbsUp = '👍';
    case Heart = '❤️';
    case Laughing = '😂';
    case Surprised = '😮';
    case Sad = '😢';
    case Pray = '🙏';
}

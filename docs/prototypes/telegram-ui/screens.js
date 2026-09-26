window.TG_UI_SCREENS = {
    "home": {
        "label": "Главная",
        "rich_message": {
            "blocks": [
                {
                    "type": "heading",
                    "text": "ASTRACAT VPN",
                    "size": 1
                },
                {
                    "type": "paragraph",
                    "text": "🟢  Всё работает"
                },
                {
                    "type": "table",
                    "cells": [
                        [
                            {
                                "text": "ПОДПИСКА"
                            },
                            {
                                "text": "🟢 Активна"
                            }
                        ],
                        [
                            {
                                "text": "ДО"
                            },
                            {
                                "text": "24.10.2026"
                            }
                        ],
                        [
                            {
                                "text": "ОСТАЛОСЬ"
                            },
                            {
                                "text": "28 дней"
                            }
                        ],
                        [
                            {
                                "text": "ТРАФИК"
                            },
                            {
                                "text": "412 / 650 GB"
                            }
                        ]
                    ],
                    "is_bordered": true,
                    "is_compact": true
                },
                {
                    "type": "divider"
                },
                {
                    "type": "buttons",
                    "buttons": [
                        {
                            "text": "Подключить VPN",
                            "callback_data": "ui:connect",
                            "style": "primary"
                        }
                    ],
                    "align": "center"
                },
                {
                    "type": "buttons",
                    "buttons": [
                        {
                            "text": "Продлить подписку",
                            "callback_data": "ui:plans",
                            "style": "success"
                        }
                    ],
                    "align": "center"
                },
                {
                    "type": "buttons",
                    "buttons": [
                        {
                            "text": "Подписка",
                            "callback_data": "ui:subscription"
                        },
                        {
                            "text": "Серверы",
                            "callback_data": "ui:servers"
                        }
                    ],
                    "align": "center"
                },
                {
                    "type": "buttons",
                    "buttons": [
                        {
                            "text": "Платежи",
                            "callback_data": "ui:payments"
                        },
                        {
                            "text": "Рефералы",
                            "callback_data": "ui:referrals"
                        }
                    ],
                    "align": "center"
                },
                {
                    "type": "buttons",
                    "buttons": [
                        {
                            "text": "Профиль",
                            "callback_data": "ui:profile"
                        },
                        {
                            "text": "Настройки",
                            "callback_data": "ui:settings"
                        }
                    ],
                    "align": "center"
                },
                {
                    "type": "buttons",
                    "buttons": [
                        {
                            "text": "Поддержка",
                            "callback_data": "ui:support"
                        }
                    ],
                    "align": "center"
                },
                {
                    "type": "footer",
                    "text": "ASTRACAT  •  status.astracat.network"
                }
            ]
        }
    },
    "subscription": {
        "label": "Подписка",
        "rich_message": {
            "blocks": [
                {
                    "type": "heading",
                    "text": "Подписка",
                    "size": 1
                },
                {
                    "type": "table",
                    "cells": [
                        [
                            {
                                "text": "Параметр",
                                "is_header": true
                            },
                            {
                                "text": "Значение",
                                "is_header": true
                            }
                        ],
                        [
                            {
                                "text": "Статус"
                            },
                            {
                                "text": "🟢 Активна"
                            }
                        ],
                        [
                            {
                                "text": "Тариф"
                            },
                            {
                                "text": "ASTRACAT VPN · 650 GB"
                            }
                        ],
                        [
                            {
                                "text": "Окончание"
                            },
                            {
                                "text": "24.10.2026"
                            }
                        ],
                        [
                            {
                                "text": "Трафик"
                            },
                            {
                                "text": "412 / 650 GB"
                            }
                        ],
                        [
                            {
                                "text": "Начало"
                            },
                            {
                                "text": "24.09.2026"
                            }
                        ]
                    ],
                    "is_bordered": true,
                    "is_compact": true
                },
                {
                    "type": "buttons",
                    "buttons": [
                        {
                            "text": "Продлить · ASTRACAT VPN · 650 GB",
                            "callback_data": "renew:demo-subscription",
                            "style": "success"
                        }
                    ],
                    "align": "center"
                },
                {
                    "type": "buttons",
                    "buttons": [
                        {
                            "text": "Включить автопродление",
                            "callback_data": "autorenew:demo-subscription"
                        }
                    ],
                    "align": "center"
                },
                {
                    "type": "buttons",
                    "buttons": [
                        {
                            "text": "Подключиться",
                            "url": "https://example.invalid/subscription/demo",
                            "style": "primary"
                        }
                    ],
                    "align": "center"
                },
                {
                    "type": "buttons",
                    "buttons": [
                        {
                            "text": "Скопировать ссылку",
                            "copy_text": {
                                "text": "https://example.invalid/subscription/demo"
                            }
                        }
                    ],
                    "align": "center"
                },
                {
                    "type": "details",
                    "summary": "Что такое трафик?",
                    "blocks": [
                        {
                            "type": "paragraph",
                            "text": "650 GB — объём данных, доступный в рамках текущего периода подписки."
                        }
                    ]
                },
                {
                    "type": "buttons",
                    "buttons": [
                        {
                            "text": "Выбрать тариф",
                            "callback_data": "ui:plans",
                            "style": "success"
                        }
                    ],
                    "align": "center"
                },
                {
                    "type": "buttons",
                    "buttons": [
                        {
                            "text": "← На главную",
                            "callback_data": "ui:home"
                        }
                    ],
                    "align": "center"
                },
                {
                    "type": "footer",
                    "text": "ASTRACAT  •  status.astracat.network"
                }
            ]
        }
    },
    "servers": {
        "label": "Серверы",
        "rich_message": {
            "blocks": [
                {
                    "type": "heading",
                    "text": "ASTRACAT Network",
                    "size": 1
                },
                {
                    "type": "paragraph",
                    "text": "🌍  5 локаций"
                },
                {
                    "type": "table",
                    "cells": [
                        [
                            {
                                "text": "Сервер",
                                "is_header": true
                            },
                            {
                                "text": "Ping",
                                "is_header": true
                            },
                            {
                                "text": "Load",
                                "is_header": true
                            },
                            {
                                "text": "Статус",
                                "is_header": true
                            }
                        ],
                        [
                            {
                                "text": "🇳🇱 NL"
                            },
                            {
                                "text": "24 ms"
                            },
                            {
                                "text": "31%"
                            },
                            {
                                "text": "🟢"
                            }
                        ],
                        [
                            {
                                "text": "🇩🇪 DE"
                            },
                            {
                                "text": "32 ms"
                            },
                            {
                                "text": "18%"
                            },
                            {
                                "text": "🟢"
                            }
                        ],
                        [
                            {
                                "text": "🇫🇷 FR"
                            },
                            {
                                "text": "39 ms"
                            },
                            {
                                "text": "67%"
                            },
                            {
                                "text": "🟡"
                            }
                        ],
                        [
                            {
                                "text": "🇬🇷 GR"
                            },
                            {
                                "text": "58 ms"
                            },
                            {
                                "text": "23%"
                            },
                            {
                                "text": "🟢"
                            }
                        ],
                        [
                            {
                                "text": "🇧🇪 BE"
                            },
                            {
                                "text": "35 ms"
                            },
                            {
                                "text": "29%"
                            },
                            {
                                "text": "🟢"
                            }
                        ]
                    ],
                    "is_bordered": true,
                    "is_compact": true,
                    "is_striped": true
                },
                {
                    "type": "details",
                    "summary": "⚙ Техническая информация",
                    "blocks": [
                        {
                            "type": "paragraph",
                            "text": "Параметры подключения определяются профилем пользователя."
                        }
                    ]
                },
                {
                    "type": "buttons",
                    "buttons": [
                        {
                            "text": "Подключиться",
                            "callback_data": "ui:connect",
                            "style": "primary"
                        }
                    ],
                    "align": "center"
                },
                {
                    "type": "buttons",
                    "buttons": [
                        {
                            "text": "← На главную",
                            "callback_data": "ui:home"
                        }
                    ],
                    "align": "center"
                },
                {
                    "type": "footer",
                    "text": "ASTRACAT  •  status.astracat.network"
                }
            ]
        }
    },
    "connect": {
        "label": "Подключение",
        "rich_message": {
            "blocks": [
                {
                    "type": "heading",
                    "text": "Подключить ASTRACAT",
                    "size": 1
                },
                {
                    "type": "paragraph",
                    "text": "Выберите устройство — покажем короткую инструкцию."
                },
                {
                    "type": "buttons",
                    "buttons": [
                        {
                            "text": "iPhone",
                            "callback_data": "ui:connect:iphone"
                        },
                        {
                            "text": "Android",
                            "callback_data": "ui:connect:android"
                        }
                    ],
                    "align": "center"
                },
                {
                    "type": "buttons",
                    "buttons": [
                        {
                            "text": "Windows",
                            "callback_data": "ui:connect:windows"
                        },
                        {
                            "text": "macOS",
                            "callback_data": "ui:connect:macos"
                        }
                    ],
                    "align": "center"
                },
                {
                    "type": "buttons",
                    "buttons": [
                        {
                            "text": "Linux",
                            "callback_data": "ui:connect:linux"
                        },
                        {
                            "text": "OpenWrt",
                            "callback_data": "ui:connect:openwrt"
                        }
                    ],
                    "align": "center"
                },
                {
                    "type": "buttons",
                    "buttons": [
                        {
                            "text": "← На главную",
                            "callback_data": "ui:home"
                        }
                    ],
                    "align": "center"
                },
                {
                    "type": "footer",
                    "text": "ASTRACAT  •  status.astracat.network"
                }
            ]
        }
    },
    "connect_iphone": {
        "label": "Инструкция · iPhone",
        "rich_message": {
            "blocks": [
                {
                    "type": "heading",
                    "text": "Подключение · iPhone",
                    "size": 1
                },
                {
                    "type": "list",
                    "items": [
                        {
                            "value": 1,
                            "blocks": [
                                {
                                    "type": "paragraph",
                                    "text": "Установите VPN-клиент для iPhone."
                                }
                            ]
                        },
                        {
                            "value": 2,
                            "blocks": [
                                {
                                    "type": "paragraph",
                                    "text": "Нажмите «Добавить ASTRACAT»."
                                }
                            ]
                        },
                        {
                            "value": 3,
                            "blocks": [
                                {
                                    "type": "paragraph",
                                    "text": "Подтвердите добавление конфигурации."
                                }
                            ]
                        },
                        {
                            "value": 4,
                            "blocks": [
                                {
                                    "type": "paragraph",
                                    "text": "Включите VPN в приложении."
                                }
                            ]
                        }
                    ],
                    "is_ordered": true
                },
                {
                    "type": "paragraph",
                    "text": "Подписка готова к подключению."
                },
                {
                    "type": "buttons",
                    "buttons": [
                        {
                            "text": "Добавить ASTRACAT",
                            "url": "https://example.invalid/subscription/demo",
                            "style": "primary"
                        }
                    ],
                    "align": "center"
                },
                {
                    "type": "buttons",
                    "buttons": [
                        {
                            "text": "Скопировать ссылку",
                            "copy_text": {
                                "text": "https://example.invalid/subscription/demo"
                            }
                        }
                    ],
                    "align": "center"
                },
                {
                    "type": "details",
                    "summary": "⚙ Ручная настройка",
                    "blocks": [
                        {
                            "type": "paragraph",
                            "text": "https://example.invalid/subscription/demo"
                        }
                    ]
                },
                {
                    "type": "buttons",
                    "buttons": [
                        {
                            "text": "← Выбрать устройство",
                            "callback_data": "ui:connect"
                        }
                    ],
                    "align": "center"
                },
                {
                    "type": "footer",
                    "text": "ASTRACAT  •  status.astracat.network"
                }
            ]
        }
    },
    "connect_android": {
        "label": "Инструкция · Android",
        "rich_message": {
            "blocks": [
                {
                    "type": "heading",
                    "text": "Подключение · Android",
                    "size": 1
                },
                {
                    "type": "list",
                    "items": [
                        {
                            "value": 1,
                            "blocks": [
                                {
                                    "type": "paragraph",
                                    "text": "Установите VPN-клиент для Android."
                                }
                            ]
                        },
                        {
                            "value": 2,
                            "blocks": [
                                {
                                    "type": "paragraph",
                                    "text": "Нажмите «Добавить ASTRACAT»."
                                }
                            ]
                        },
                        {
                            "value": 3,
                            "blocks": [
                                {
                                    "type": "paragraph",
                                    "text": "Подтвердите добавление конфигурации."
                                }
                            ]
                        },
                        {
                            "value": 4,
                            "blocks": [
                                {
                                    "type": "paragraph",
                                    "text": "Включите VPN в приложении."
                                }
                            ]
                        }
                    ],
                    "is_ordered": true
                },
                {
                    "type": "paragraph",
                    "text": "Подписка готова к подключению."
                },
                {
                    "type": "buttons",
                    "buttons": [
                        {
                            "text": "Добавить ASTRACAT",
                            "url": "https://example.invalid/subscription/demo",
                            "style": "primary"
                        }
                    ],
                    "align": "center"
                },
                {
                    "type": "buttons",
                    "buttons": [
                        {
                            "text": "Скопировать ссылку",
                            "copy_text": {
                                "text": "https://example.invalid/subscription/demo"
                            }
                        }
                    ],
                    "align": "center"
                },
                {
                    "type": "details",
                    "summary": "⚙ Ручная настройка",
                    "blocks": [
                        {
                            "type": "paragraph",
                            "text": "https://example.invalid/subscription/demo"
                        }
                    ]
                },
                {
                    "type": "buttons",
                    "buttons": [
                        {
                            "text": "← Выбрать устройство",
                            "callback_data": "ui:connect"
                        }
                    ],
                    "align": "center"
                },
                {
                    "type": "footer",
                    "text": "ASTRACAT  •  status.astracat.network"
                }
            ]
        }
    },
    "connect_windows": {
        "label": "Инструкция · Windows",
        "rich_message": {
            "blocks": [
                {
                    "type": "heading",
                    "text": "Подключение · Windows",
                    "size": 1
                },
                {
                    "type": "list",
                    "items": [
                        {
                            "value": 1,
                            "blocks": [
                                {
                                    "type": "paragraph",
                                    "text": "Установите VPN-клиент для Windows."
                                }
                            ]
                        },
                        {
                            "value": 2,
                            "blocks": [
                                {
                                    "type": "paragraph",
                                    "text": "Нажмите «Добавить ASTRACAT»."
                                }
                            ]
                        },
                        {
                            "value": 3,
                            "blocks": [
                                {
                                    "type": "paragraph",
                                    "text": "Подтвердите добавление конфигурации."
                                }
                            ]
                        },
                        {
                            "value": 4,
                            "blocks": [
                                {
                                    "type": "paragraph",
                                    "text": "Включите VPN в приложении."
                                }
                            ]
                        }
                    ],
                    "is_ordered": true
                },
                {
                    "type": "paragraph",
                    "text": "Подписка готова к подключению."
                },
                {
                    "type": "buttons",
                    "buttons": [
                        {
                            "text": "Добавить ASTRACAT",
                            "url": "https://example.invalid/subscription/demo",
                            "style": "primary"
                        }
                    ],
                    "align": "center"
                },
                {
                    "type": "buttons",
                    "buttons": [
                        {
                            "text": "Скопировать ссылку",
                            "copy_text": {
                                "text": "https://example.invalid/subscription/demo"
                            }
                        }
                    ],
                    "align": "center"
                },
                {
                    "type": "details",
                    "summary": "⚙ Ручная настройка",
                    "blocks": [
                        {
                            "type": "paragraph",
                            "text": "https://example.invalid/subscription/demo"
                        }
                    ]
                },
                {
                    "type": "buttons",
                    "buttons": [
                        {
                            "text": "← Выбрать устройство",
                            "callback_data": "ui:connect"
                        }
                    ],
                    "align": "center"
                },
                {
                    "type": "footer",
                    "text": "ASTRACAT  •  status.astracat.network"
                }
            ]
        }
    },
    "connect_macos": {
        "label": "Инструкция · macOS",
        "rich_message": {
            "blocks": [
                {
                    "type": "heading",
                    "text": "Подключение · macOS",
                    "size": 1
                },
                {
                    "type": "list",
                    "items": [
                        {
                            "value": 1,
                            "blocks": [
                                {
                                    "type": "paragraph",
                                    "text": "Установите VPN-клиент для macOS."
                                }
                            ]
                        },
                        {
                            "value": 2,
                            "blocks": [
                                {
                                    "type": "paragraph",
                                    "text": "Нажмите «Добавить ASTRACAT»."
                                }
                            ]
                        },
                        {
                            "value": 3,
                            "blocks": [
                                {
                                    "type": "paragraph",
                                    "text": "Подтвердите добавление конфигурации."
                                }
                            ]
                        },
                        {
                            "value": 4,
                            "blocks": [
                                {
                                    "type": "paragraph",
                                    "text": "Включите VPN в приложении."
                                }
                            ]
                        }
                    ],
                    "is_ordered": true
                },
                {
                    "type": "paragraph",
                    "text": "Подписка готова к подключению."
                },
                {
                    "type": "buttons",
                    "buttons": [
                        {
                            "text": "Добавить ASTRACAT",
                            "url": "https://example.invalid/subscription/demo",
                            "style": "primary"
                        }
                    ],
                    "align": "center"
                },
                {
                    "type": "buttons",
                    "buttons": [
                        {
                            "text": "Скопировать ссылку",
                            "copy_text": {
                                "text": "https://example.invalid/subscription/demo"
                            }
                        }
                    ],
                    "align": "center"
                },
                {
                    "type": "details",
                    "summary": "⚙ Ручная настройка",
                    "blocks": [
                        {
                            "type": "paragraph",
                            "text": "https://example.invalid/subscription/demo"
                        }
                    ]
                },
                {
                    "type": "buttons",
                    "buttons": [
                        {
                            "text": "← Выбрать устройство",
                            "callback_data": "ui:connect"
                        }
                    ],
                    "align": "center"
                },
                {
                    "type": "footer",
                    "text": "ASTRACAT  •  status.astracat.network"
                }
            ]
        }
    },
    "connect_linux": {
        "label": "Инструкция · Linux",
        "rich_message": {
            "blocks": [
                {
                    "type": "heading",
                    "text": "Подключение · Linux",
                    "size": 1
                },
                {
                    "type": "list",
                    "items": [
                        {
                            "value": 1,
                            "blocks": [
                                {
                                    "type": "paragraph",
                                    "text": "Установите VPN-клиент для Linux."
                                }
                            ]
                        },
                        {
                            "value": 2,
                            "blocks": [
                                {
                                    "type": "paragraph",
                                    "text": "Нажмите «Добавить ASTRACAT»."
                                }
                            ]
                        },
                        {
                            "value": 3,
                            "blocks": [
                                {
                                    "type": "paragraph",
                                    "text": "Подтвердите добавление конфигурации."
                                }
                            ]
                        },
                        {
                            "value": 4,
                            "blocks": [
                                {
                                    "type": "paragraph",
                                    "text": "Включите VPN в приложении."
                                }
                            ]
                        }
                    ],
                    "is_ordered": true
                },
                {
                    "type": "paragraph",
                    "text": "Подписка готова к подключению."
                },
                {
                    "type": "buttons",
                    "buttons": [
                        {
                            "text": "Добавить ASTRACAT",
                            "url": "https://example.invalid/subscription/demo",
                            "style": "primary"
                        }
                    ],
                    "align": "center"
                },
                {
                    "type": "buttons",
                    "buttons": [
                        {
                            "text": "Скопировать ссылку",
                            "copy_text": {
                                "text": "https://example.invalid/subscription/demo"
                            }
                        }
                    ],
                    "align": "center"
                },
                {
                    "type": "details",
                    "summary": "⚙ Ручная настройка",
                    "blocks": [
                        {
                            "type": "paragraph",
                            "text": "https://example.invalid/subscription/demo"
                        }
                    ]
                },
                {
                    "type": "buttons",
                    "buttons": [
                        {
                            "text": "← Выбрать устройство",
                            "callback_data": "ui:connect"
                        }
                    ],
                    "align": "center"
                },
                {
                    "type": "footer",
                    "text": "ASTRACAT  •  status.astracat.network"
                }
            ]
        }
    },
    "connect_openwrt": {
        "label": "Инструкция · OpenWrt",
        "rich_message": {
            "blocks": [
                {
                    "type": "heading",
                    "text": "Подключение · OpenWrt",
                    "size": 1
                },
                {
                    "type": "list",
                    "items": [
                        {
                            "value": 1,
                            "blocks": [
                                {
                                    "type": "paragraph",
                                    "text": "Установите VPN-клиент для OpenWrt."
                                }
                            ]
                        },
                        {
                            "value": 2,
                            "blocks": [
                                {
                                    "type": "paragraph",
                                    "text": "Нажмите «Добавить ASTRACAT»."
                                }
                            ]
                        },
                        {
                            "value": 3,
                            "blocks": [
                                {
                                    "type": "paragraph",
                                    "text": "Подтвердите добавление конфигурации."
                                }
                            ]
                        },
                        {
                            "value": 4,
                            "blocks": [
                                {
                                    "type": "paragraph",
                                    "text": "Включите VPN в приложении."
                                }
                            ]
                        }
                    ],
                    "is_ordered": true
                },
                {
                    "type": "paragraph",
                    "text": "Подписка готова к подключению."
                },
                {
                    "type": "buttons",
                    "buttons": [
                        {
                            "text": "Добавить ASTRACAT",
                            "url": "https://example.invalid/subscription/demo",
                            "style": "primary"
                        }
                    ],
                    "align": "center"
                },
                {
                    "type": "buttons",
                    "buttons": [
                        {
                            "text": "Скопировать ссылку",
                            "copy_text": {
                                "text": "https://example.invalid/subscription/demo"
                            }
                        }
                    ],
                    "align": "center"
                },
                {
                    "type": "details",
                    "summary": "⚙ Ручная настройка",
                    "blocks": [
                        {
                            "type": "paragraph",
                            "text": "https://example.invalid/subscription/demo"
                        }
                    ]
                },
                {
                    "type": "buttons",
                    "buttons": [
                        {
                            "text": "← Выбрать устройство",
                            "callback_data": "ui:connect"
                        }
                    ],
                    "align": "center"
                },
                {
                    "type": "footer",
                    "text": "ASTRACAT  •  status.astracat.network"
                }
            ]
        }
    },
    "plans": {
        "label": "Продление",
        "rich_message": {
            "blocks": [
                {
                    "type": "heading",
                    "text": "Продлить ASTRACAT",
                    "size": 1
                },
                {
                    "type": "paragraph",
                    "text": "Выберите период — стоимость и объём трафика сразу видны в таблице."
                },
                {
                    "type": "table",
                    "cells": [
                        [
                            {
                                "text": "Период",
                                "is_header": true
                            },
                            {
                                "text": "Трафик",
                                "is_header": true
                            },
                            {
                                "text": "Цена",
                                "is_header": true
                            }
                        ],
                        [
                            {
                                "text": "1 месяц"
                            },
                            {
                                "text": "650 GB"
                            },
                            {
                                "text": "199 ₽"
                            }
                        ],
                        [
                            {
                                "text": "3 месяца"
                            },
                            {
                                "text": "1 950 GB"
                            },
                            {
                                "text": "597 ₽"
                            }
                        ],
                        [
                            {
                                "text": "6 месяцев"
                            },
                            {
                                "text": "3 900 GB"
                            },
                            {
                                "text": "1 194 ₽"
                            }
                        ],
                        [
                            {
                                "text": "12 месяцев"
                            },
                            {
                                "text": "7 800 GB"
                            },
                            {
                                "text": "2 388 ₽"
                            }
                        ]
                    ],
                    "is_bordered": true,
                    "is_compact": true,
                    "is_striped": true
                },
                {
                    "type": "buttons",
                    "buttons": [
                        {
                            "text": "1 месяц · 199 ₽",
                            "callback_data": "ui:plan:month-1",
                            "style": "success"
                        }
                    ],
                    "align": "center"
                },
                {
                    "type": "buttons",
                    "buttons": [
                        {
                            "text": "3 месяца · 597 ₽",
                            "callback_data": "ui:plan:month-3"
                        }
                    ],
                    "align": "center"
                },
                {
                    "type": "buttons",
                    "buttons": [
                        {
                            "text": "6 месяцев · 1 194 ₽",
                            "callback_data": "ui:plan:month-6"
                        }
                    ],
                    "align": "center"
                },
                {
                    "type": "buttons",
                    "buttons": [
                        {
                            "text": "12 месяцев · 2 388 ₽",
                            "callback_data": "ui:plan:month-12"
                        }
                    ],
                    "align": "center"
                },
                {
                    "type": "buttons",
                    "buttons": [
                        {
                            "text": "← На главную",
                            "callback_data": "ui:home"
                        }
                    ],
                    "align": "center"
                },
                {
                    "type": "footer",
                    "text": "ASTRACAT  •  status.astracat.network"
                }
            ]
        }
    },
    "plan_1": {
        "label": "1 месяц · 199 ₽",
        "rich_message": {
            "blocks": [
                {
                    "type": "heading",
                    "text": "Продление подписки",
                    "size": 1
                },
                {
                    "type": "table",
                    "cells": [
                        [
                            {
                                "text": "Период"
                            },
                            {
                                "text": "Трафик"
                            }
                        ],
                        [
                            {
                                "text": "1 месяц"
                            },
                            {
                                "text": "650 GB"
                            }
                        ]
                    ],
                    "is_bordered": true,
                    "is_compact": true
                },
                {
                    "type": "heading",
                    "text": "199 ₽",
                    "size": 2
                },
                {
                    "type": "paragraph",
                    "text": "Подписка будет продлена после подтверждения оплаты."
                },
                {
                    "type": "buttons",
                    "buttons": [
                        {
                            "text": "Продолжить · 199 ₽",
                            "callback_data": "buy:month-1",
                            "style": "success"
                        }
                    ],
                    "align": "center"
                },
                {
                    "type": "buttons",
                    "buttons": [
                        {
                            "text": "← Выбрать период",
                            "callback_data": "ui:plans"
                        }
                    ],
                    "align": "center"
                },
                {
                    "type": "footer",
                    "text": "ASTRACAT  •  status.astracat.network"
                }
            ]
        }
    },
    "plan_3": {
        "label": "3 месяца · 597 ₽",
        "rich_message": {
            "blocks": [
                {
                    "type": "heading",
                    "text": "Продление подписки",
                    "size": 1
                },
                {
                    "type": "table",
                    "cells": [
                        [
                            {
                                "text": "Период"
                            },
                            {
                                "text": "Трафик"
                            }
                        ],
                        [
                            {
                                "text": "3 месяца"
                            },
                            {
                                "text": "1 950 GB"
                            }
                        ]
                    ],
                    "is_bordered": true,
                    "is_compact": true
                },
                {
                    "type": "heading",
                    "text": "597 ₽",
                    "size": 2
                },
                {
                    "type": "paragraph",
                    "text": "Подписка будет продлена после подтверждения оплаты."
                },
                {
                    "type": "buttons",
                    "buttons": [
                        {
                            "text": "Продолжить · 597 ₽",
                            "callback_data": "buy:month-3",
                            "style": "success"
                        }
                    ],
                    "align": "center"
                },
                {
                    "type": "buttons",
                    "buttons": [
                        {
                            "text": "← Выбрать период",
                            "callback_data": "ui:plans"
                        }
                    ],
                    "align": "center"
                },
                {
                    "type": "footer",
                    "text": "ASTRACAT  •  status.astracat.network"
                }
            ]
        }
    },
    "plan_6": {
        "label": "6 месяцев · 1 194 ₽",
        "rich_message": {
            "blocks": [
                {
                    "type": "heading",
                    "text": "Продление подписки",
                    "size": 1
                },
                {
                    "type": "table",
                    "cells": [
                        [
                            {
                                "text": "Период"
                            },
                            {
                                "text": "Трафик"
                            }
                        ],
                        [
                            {
                                "text": "6 месяцев"
                            },
                            {
                                "text": "3 900 GB"
                            }
                        ]
                    ],
                    "is_bordered": true,
                    "is_compact": true
                },
                {
                    "type": "heading",
                    "text": "1 194 ₽",
                    "size": 2
                },
                {
                    "type": "paragraph",
                    "text": "Подписка будет продлена после подтверждения оплаты."
                },
                {
                    "type": "buttons",
                    "buttons": [
                        {
                            "text": "Продолжить · 1 194 ₽",
                            "callback_data": "buy:month-6",
                            "style": "success"
                        }
                    ],
                    "align": "center"
                },
                {
                    "type": "buttons",
                    "buttons": [
                        {
                            "text": "← Выбрать период",
                            "callback_data": "ui:plans"
                        }
                    ],
                    "align": "center"
                },
                {
                    "type": "footer",
                    "text": "ASTRACAT  •  status.astracat.network"
                }
            ]
        }
    },
    "plan_12": {
        "label": "12 месяцев · 2 388 ₽",
        "rich_message": {
            "blocks": [
                {
                    "type": "heading",
                    "text": "Продление подписки",
                    "size": 1
                },
                {
                    "type": "table",
                    "cells": [
                        [
                            {
                                "text": "Период"
                            },
                            {
                                "text": "Трафик"
                            }
                        ],
                        [
                            {
                                "text": "12 месяцев"
                            },
                            {
                                "text": "7 800 GB"
                            }
                        ]
                    ],
                    "is_bordered": true,
                    "is_compact": true
                },
                {
                    "type": "heading",
                    "text": "2 388 ₽",
                    "size": 2
                },
                {
                    "type": "paragraph",
                    "text": "Подписка будет продлена после подтверждения оплаты."
                },
                {
                    "type": "buttons",
                    "buttons": [
                        {
                            "text": "Продолжить · 2 388 ₽",
                            "callback_data": "buy:month-12",
                            "style": "success"
                        }
                    ],
                    "align": "center"
                },
                {
                    "type": "buttons",
                    "buttons": [
                        {
                            "text": "← Выбрать период",
                            "callback_data": "ui:plans"
                        }
                    ],
                    "align": "center"
                },
                {
                    "type": "footer",
                    "text": "ASTRACAT  •  status.astracat.network"
                }
            ]
        }
    },
    "payment_success": {
        "label": "Оплата прошла",
        "rich_message": {
            "blocks": [
                {
                    "type": "heading",
                    "text": "Оплата прошла",
                    "size": 1
                },
                {
                    "type": "paragraph",
                    "text": "Платёж успешно обработан. Подписка продлена."
                },
                {
                    "type": "table",
                    "cells": [
                        [
                            {
                                "text": "Платёж",
                                "is_header": true
                            },
                            {
                                "text": "Детали",
                                "is_header": true
                            }
                        ],
                        [
                            {
                                "text": "Сумма"
                            },
                            {
                                "text": "199 ₽"
                            }
                        ],
                        [
                            {
                                "text": "Подписка"
                            },
                            {
                                "text": "+30 дней"
                            }
                        ],
                        [
                            {
                                "text": "Действует до"
                            },
                            {
                                "text": "24 ноября"
                            }
                        ],
                        [
                            {
                                "text": "Статус"
                            },
                            {
                                "text": "✅ Оплачено"
                            }
                        ]
                    ],
                    "is_bordered": true,
                    "is_compact": true
                },
                {
                    "type": "buttons",
                    "buttons": [
                        {
                            "text": "Подключиться",
                            "callback_data": "ui:connect",
                            "style": "primary"
                        }
                    ],
                    "align": "center"
                },
                {
                    "type": "buttons",
                    "buttons": [
                        {
                            "text": "На главную",
                            "callback_data": "ui:home"
                        }
                    ],
                    "align": "center"
                },
                {
                    "type": "footer",
                    "text": "ASTRACAT  •  status.astracat.network"
                }
            ]
        }
    },
    "payments": {
        "label": "Платежи",
        "rich_message": {
            "blocks": [
                {
                    "type": "heading",
                    "text": "Платежи",
                    "size": 1
                },
                {
                    "type": "table",
                    "cells": [
                        [
                            {
                                "text": "Дата",
                                "is_header": true
                            },
                            {
                                "text": "Покупка",
                                "is_header": true
                            },
                            {
                                "text": "Сумма",
                                "is_header": true
                            }
                        ],
                        [
                            {
                                "text": "24 сен"
                            },
                            {
                                "text": "VPN · 1 месяц"
                            },
                            {
                                "text": "199 ₽"
                            }
                        ],
                        [
                            {
                                "text": "24 авг"
                            },
                            {
                                "text": "VPN · 1 месяц"
                            },
                            {
                                "text": "199 ₽"
                            }
                        ],
                        [
                            {
                                "text": "24 июл"
                            },
                            {
                                "text": "VPN · 3 месяца"
                            },
                            {
                                "text": "597 ₽"
                            }
                        ]
                    ],
                    "is_bordered": true,
                    "is_compact": true,
                    "is_striped": true
                },
                {
                    "type": "buttons",
                    "buttons": [
                        {
                            "text": "Продлить подписку",
                            "callback_data": "ui:plans",
                            "style": "success"
                        }
                    ],
                    "align": "center"
                },
                {
                    "type": "buttons",
                    "buttons": [
                        {
                            "text": "← На главную",
                            "callback_data": "ui:home"
                        }
                    ],
                    "align": "center"
                },
                {
                    "type": "footer",
                    "text": "ASTRACAT  •  status.astracat.network"
                }
            ]
        }
    },
    "network": {
        "label": "Состояние сети",
        "rich_message": {
            "blocks": [
                {
                    "type": "heading",
                    "text": "ASTRACAT Network",
                    "size": 1
                },
                {
                    "type": "paragraph",
                    "text": "Состояние компонентов backend"
                },
                {
                    "type": "table",
                    "cells": [
                        [
                            {
                                "text": "Сервис",
                                "is_header": true
                            },
                            {
                                "text": "Состояние",
                                "is_header": true
                            }
                        ],
                        [
                            {
                                "text": "VPN"
                            },
                            {
                                "text": "🟢 Operational"
                            }
                        ],
                        [
                            {
                                "text": "DNS"
                            },
                            {
                                "text": "🟢 Operational"
                            }
                        ],
                        [
                            {
                                "text": "Payments"
                            },
                            {
                                "text": "🟢 Operational"
                            }
                        ],
                        [
                            {
                                "text": "API"
                            },
                            {
                                "text": "🟡 Degraded · demo"
                            }
                        ]
                    ],
                    "is_bordered": true,
                    "is_compact": true
                },
                {
                    "type": "expandable_blockquote",
                    "text": "Статус компонента обновляется фоновым процессом. Если предупреждение сохраняется, напишите в поддержку.",
                    "credit": "Справка ASTRACAT"
                },
                {
                    "type": "buttons",
                    "buttons": [
                        {
                            "text": "Серверы",
                            "callback_data": "ui:servers"
                        }
                    ],
                    "align": "center"
                },
                {
                    "type": "buttons",
                    "buttons": [
                        {
                            "text": "← На главную",
                            "callback_data": "ui:home"
                        }
                    ],
                    "align": "center"
                },
                {
                    "type": "footer",
                    "text": "ASTRACAT  •  status.astracat.network"
                }
            ]
        }
    },
    "referrals": {
        "label": "Рефералы",
        "rich_message": {
            "blocks": [
                {
                    "type": "heading",
                    "text": "Рефералы",
                    "size": 1
                },
                {
                    "type": "paragraph",
                    "text": "Приглашайте друзей и получайте бонусы за их подписки."
                },
                {
                    "type": "table",
                    "cells": [
                        [
                            {
                                "text": "Показатель",
                                "is_header": true
                            },
                            {
                                "text": "Всего",
                                "is_header": true
                            }
                        ],
                        [
                            {
                                "text": "Приглашено"
                            },
                            {
                                "text": "8"
                            }
                        ],
                        [
                            {
                                "text": "Оплатили"
                            },
                            {
                                "text": "5"
                            }
                        ],
                        [
                            {
                                "text": "Заработано"
                            },
                            {
                                "text": "1 240,00 ₽"
                            }
                        ],
                        [
                            {
                                "text": "Ваша ссылка"
                            },
                            {
                                "text": "https://t.me/astracat_bot?start=ref_demo"
                            }
                        ]
                    ],
                    "is_bordered": true,
                    "is_compact": true
                },
                {
                    "type": "buttons",
                    "buttons": [
                        {
                            "text": "Пригласить друга",
                            "url": "https://t.me/astracat_bot?start=ref_demo",
                            "style": "primary"
                        }
                    ],
                    "align": "center"
                },
                {
                    "type": "buttons",
                    "buttons": [
                        {
                            "text": "Скопировать ссылку",
                            "copy_text": {
                                "text": "https://t.me/astracat_bot?start=ref_demo"
                            }
                        }
                    ],
                    "align": "center"
                },
                {
                    "type": "details",
                    "summary": "Как начисляется бонус?",
                    "blocks": [
                        {
                            "type": "paragraph",
                            "text": "Бонус начисляется после успешной оплаты приглашённого пользователя согласно условиям программы."
                        }
                    ]
                },
                {
                    "type": "buttons",
                    "buttons": [
                        {
                            "text": "← На главную",
                            "callback_data": "ui:home"
                        }
                    ],
                    "align": "center"
                },
                {
                    "type": "footer",
                    "text": "ASTRACAT  •  status.astracat.network"
                }
            ]
        }
    },
    "profile": {
        "label": "Профиль",
        "rich_message": {
            "blocks": [
                {
                    "type": "heading",
                    "text": "Профиль",
                    "size": 1
                },
                {
                    "type": "table",
                    "cells": [
                        [
                            {
                                "text": "Поле",
                                "is_header": true
                            },
                            {
                                "text": "Данные",
                                "is_header": true
                            }
                        ],
                        [
                            {
                                "text": "Аккаунт"
                            },
                            {
                                "text": "astracat member"
                            }
                        ],
                        [
                            {
                                "text": "Telegram ID"
                            },
                            {
                                "text": "123456789"
                            }
                        ],
                        [
                            {
                                "text": "Баланс"
                            },
                            {
                                "text": "0,00 ₽"
                            }
                        ]
                    ],
                    "is_bordered": true,
                    "is_compact": true
                },
                {
                    "type": "buttons",
                    "buttons": [
                        {
                            "text": "Настройки",
                            "callback_data": "ui:settings"
                        }
                    ],
                    "align": "center"
                },
                {
                    "type": "buttons",
                    "buttons": [
                        {
                            "text": "← На главную",
                            "callback_data": "ui:home"
                        }
                    ],
                    "align": "center"
                },
                {
                    "type": "footer",
                    "text": "ASTRACAT  •  status.astracat.network"
                }
            ]
        }
    },
    "settings": {
        "label": "Настройки",
        "rich_message": {
            "blocks": [
                {
                    "type": "heading",
                    "text": "Настройки",
                    "size": 1
                },
                {
                    "type": "table",
                    "cells": [
                        [
                            {
                                "text": "Параметр",
                                "is_header": true
                            },
                            {
                                "text": "Состояние",
                                "is_header": true
                            }
                        ],
                        [
                            {
                                "text": "Уведомления"
                            },
                            {
                                "text": "Включены"
                            }
                        ],
                        [
                            {
                                "text": "Автопродление"
                            },
                            {
                                "text": "Выключено"
                            }
                        ],
                        [
                            {
                                "text": "Язык"
                            },
                            {
                                "text": "Русский"
                            }
                        ]
                    ],
                    "is_bordered": true,
                    "is_compact": true
                },
                {
                    "type": "details",
                    "summary": "Уведомления",
                    "blocks": [
                        {
                            "type": "paragraph",
                            "text": "Сообщаем об оплатах и скором окончании подписки."
                        }
                    ]
                },
                {
                    "type": "details",
                    "summary": "Конфиденциальность",
                    "blocks": [
                        {
                            "type": "paragraph",
                            "text": "Данные аккаунта используются только для работы подписки и поддержки."
                        }
                    ]
                },
                {
                    "type": "buttons",
                    "buttons": [
                        {
                            "text": "Поддержка",
                            "callback_data": "ui:support"
                        }
                    ],
                    "align": "center"
                },
                {
                    "type": "buttons",
                    "buttons": [
                        {
                            "text": "← На главную",
                            "callback_data": "ui:home"
                        }
                    ],
                    "align": "center"
                },
                {
                    "type": "footer",
                    "text": "ASTRACAT  •  status.astracat.network"
                }
            ]
        }
    },
    "support": {
        "label": "Поддержка",
        "rich_message": {
            "blocks": [
                {
                    "type": "heading",
                    "text": "Поддержка",
                    "size": 1
                },
                {
                    "type": "paragraph",
                    "text": "Мы поможем с оплатой, настройкой подключения и вопросами по подписке."
                },
                {
                    "type": "paragraph",
                    "text": "Ссылка поддержки не настроена."
                },
                {
                    "type": "buttons",
                    "buttons": [
                        {
                            "text": "← На главную",
                            "callback_data": "ui:home"
                        }
                    ],
                    "align": "center"
                },
                {
                    "type": "footer",
                    "text": "ASTRACAT  •  status.astracat.network"
                }
            ]
        }
    }
};

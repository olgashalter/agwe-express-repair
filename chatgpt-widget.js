// Простой ИИ-ассистент для автосервиса AGWE
// Версия 3.0 - поле ввода активно сразу

const CHAT_API_URL = '/chat.php';
let chatHistory = [];
let isBooking = false;
let bookingData = {};

document.addEventListener('DOMContentLoaded', initChatWidget);

function initChatWidget() {
  // Создаем кнопку чата
  createChatButton();
  
  // Создаем окно чата
  createChatWindow();
  
  // Загружаем услуги (для внутреннего использования)
  loadServices();
}

function createChatButton() {
  // Удаляем существующую кнопку, если есть
  const existingButton = document.getElementById('chat-btn');
  if (existingButton) existingButton.remove();
  
  const btn = document.createElement('div');
  btn.id = 'chat-btn';
  btn.innerHTML = '💬';
  btn.title = 'Онлайн консультация';
  btn.style.cssText = `
    position: fixed;
    bottom: 30px;
    right: 30px;
    width: 60px;
    height: 60px;
    border-radius: 50%;
    background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
    color: white;
    font-size: 28px;
    cursor: pointer;
    box-shadow: 0 4px 15px rgba(0,0,0,0.2);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    transition: all 0.3s ease;
    opacity: 1;
    visibility: visible;
  `;
  
  // Эффект при наведении
  btn.onmouseover = () => {
    btn.style.transform = 'scale(1.1)';
    btn.style.boxShadow = '0 6px 20px rgba(0,0,0,0.3)';
  };
  
  btn.onmouseout = () => {
    btn.style.transform = 'scale(1)';
    btn.style.boxShadow = '0 4px 15px rgba(0,0,0,0.2)';
  };
  
  btn.onclick = () => {
    const chatWindow = document.getElementById('chat-win');
    if (chatWindow.style.display === 'none' || chatWindow.style.display === '') {
      chatWindow.style.display = 'flex';
      // Показываем приветствие при первом открытии
      if (chatHistory.length === 0) {
        setTimeout(() => {
          addMessage('assistant', 'Здравствуйте! Я — онлайн-ассистент автосервиса AGWE. Могу помочь с ремонтом тентов, подготовкой TIR или записью на сервис. Что вас интересует?');
        }, 300);
      }
    } else {
      chatWindow.style.display = 'none';
    }
  };
  
  document.body.appendChild(btn);
}

function createChatWindow() {
  // Удаляем существующее окно, если есть
  const existingWindow = document.getElementById('chat-win');
  if (existingWindow) existingWindow.remove();
  
  const win = document.createElement('div');
  win.id = 'chat-win';
  win.style.cssText = `
    display: none;
    position: fixed;
    bottom: 100px;
    right: 30px;
    width: 350px;
    height: 500px;
    max-height: 80vh;
    background: white;
    border-radius: 12px;
    box-shadow: 0 10px 40px rgba(0,0,0,0.2);
    z-index: 9998;
    flex-direction: column;
    overflow: hidden;
    font-family: Arial, sans-serif;
  `;
  
  win.innerHTML = `
    <div style="background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%); color: white; padding: 15px; text-align: center;">
      <h3 style="margin: 0; font-size: 18px; display: flex; align-items: center; justify-content: center;">
        <span style="margin-right: 10px;">🤖</span> Онлайн консультация
      </h3>
      <p style="margin: 5px 0 0 0; font-size: 12px; opacity: 0.9;">AGWE - Ремонт тентов 24/7</p>
    </div>
    <div id="chat-messages" style="flex: 1; padding: 15px; overflow-y: auto; background: #f9f9f9; display: flex; flex-direction: column;"></div>
    <div style="padding: 10px; border-top: 1px solid #e0e0e0; background: white;">
      <textarea id="chat-input" placeholder="Напишите ваш вопрос..." 
                style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px; resize: none; height: 60px; font-size: 14px; margin-bottom: 8px;"></textarea>
      <button id="chat-send" style="width: 100%; padding: 10px; background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%); color: white; border: none; border-radius: 6px; font-size: 14px; cursor: pointer;">Отправить</button>
    </div>
  `;
  
  document.body.appendChild(win);
  
  // Обработчики событий
  document.getElementById('chat-send').addEventListener('click', sendMessage);
  document.getElementById('chat-input').addEventListener('keypress', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      sendMessage();
    }
  });
}

function loadServices() {
  // Загружаем услуги для внутреннего использования
  fetch(CHAT_API_URL + '?action=get_services')
    .then(response => response.json())
    .then(services => {
      console.log('Услуги загружены:', services);
    })
    .catch(error => {
      console.error('Ошибка загрузки услуг:', error);
    });
}

function sendMessage() {
  const input = document.getElementById('chat-input');
  const message = input.value.trim();
  
  if (!message) return;
  
  // Добавляем сообщение пользователя
  addMessage('user', message);
  input.value = '';
  
  // Показываем "печать..."
  const thinkingMsg = addMessage('assistant', 'ИИ думает...');
  
  // Сохраняем историю
  chatHistory.push({ role: 'user', content: message });
  
  // Отправляем запрос к серверу
  fetch(CHAT_API_URL, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      messages: chatHistory,
      model: 'gpt-3.5-turbo',
      temperature: 0.7,
      max_tokens: 300
    })
  })
  .then(response => {
    if (!response.ok) {
      throw new Error('Ошибка сервера: ' + response.status);
    }
    return response.json();
  })
  .then(data => {
    // Обработка специальных действий
    if (data.action) {
      handleSpecialAction(data, thinkingMsg);
    } else {
      // Обычный ответ
      thinkingMsg.querySelector('.msg-text').textContent = data.choices[0].message.content;
      chatHistory.push({ role: 'assistant', content: data.choices[0].message.content });
    }
  })
  .catch(error => {
    console.error('Ошибка:', error);
    thinkingMsg.querySelector('.msg-text').textContent = 'Извините, произошла ошибка. Попробуйте позже или позвоните нам: +372 555 88 048';
  });
}

function sendMessageWithAction(action) {
  // Добавляем сообщение пользователя
  addMessage('user', 'Выбираю время');
  
  // Показываем "печать..."
  const thinkingMsg = addMessage('assistant', 'ИИ думает...');
  
  // Сохраняем историю
  chatHistory.push({ role: 'user', content: JSON.stringify(action) });
  
  // Отправляем запрос к серверу
  fetch(CHAT_API_URL, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      messages: chatHistory,
      specialAction: action,
      model: 'gpt-3.5-turbo',
      temperature: 0.7,
      max_tokens: 300
    })
  })
  .then(response => {
    if (!response.ok) {
      throw new Error('Ошибка сервера: ' + response.status);
    }
    return response.json();
  })
  .then(data => {
    // Обработка специальных действий
    if (data.action) {
      handleSpecialAction(data, thinkingMsg);
    } else {
      // Обычный ответ
      thinkingMsg.querySelector('.msg-text').textContent = data.choices[0].message.content;
      chatHistory.push({ role: 'assistant', content: data.choices[0].message.content });
    }
  })
  .catch(error => {
    console.error('Ошибка:', error);
    thinkingMsg.querySelector('.msg-text').textContent = 'Извините, произошла ошибка. Попробуйте позже или позвоните нам: +372 555 88 048';
  });
}

function handleSpecialAction(action, thinkingMsg) {
  switch (action.action) {
    case 'booking_form':
      const step = action.step;

      if (step === 1) {
        thinkingMsg.querySelector('.msg-text').textContent = action.message;

        // Если есть варианты времени, показываем их
        if (action.timeOptions) {
          const timeOptionsDiv = document.createElement('div');
          timeOptionsDiv.style.marginTop = '10px';
          timeOptionsDiv.innerHTML = '<strong>Доступное время:</strong><br>' + 
            action.timeOptions.replace(/\n/g, '<br>');
          thinkingMsg.appendChild(timeOptionsDiv);
        }

        // Если есть список слотов, показываем кнопки выбора
        if (Array.isArray(action.slots) && action.slots.length > 0) {
          const buttonsDiv = document.createElement('div');
          buttonsDiv.style.cssText = 'margin-top: 10px; display: flex; flex-wrap: wrap; gap: 5px;';
          
          // Показываем первые 6 слотов как кнопки (чтобы не перегружать интерфейс)
          action.slots.slice(0, 6).forEach((slot, index) => {
            const btn = document.createElement('button');
            const timeStr = slot.split(' ')[1]; // Извлекаем только время (например, "09:00")
            btn.textContent = timeStr;
            btn.style.cssText = 'padding: 5px 10px; background: #e0e0e0; border: none; border-radius: 4px; cursor: pointer; font-size: 14px;';
            btn.onclick = () => {
              addMessage('user', `Выбираю ${timeStr}`);
              sendMessageWithAction({
                action: 'booking_form',
                step: 4,
                sessionId: action.sessionId,
                value: index + 1, // Номер слота (1-based)
                slots: action.slots,
                field: 'timeSlot'
              });
            };
            buttonsDiv.appendChild(btn);
          });

          thinkingMsg.appendChild(buttonsDiv);
        }
        
        // Если есть ошибка, показываем сообщение об ошибке
        if (action.errorDetails) {
          const errorDiv = document.createElement('div');
          errorDiv.style.cssText = 'margin-top: 10px; color: #d32f2f; font-size: 0.9em;';
          errorDiv.textContent = 'Ошибка получения времени. Пожалуйста, позвоните нам: +372 555 88 048';
          thinkingMsg.appendChild(errorDiv);
        }
      } else if (step === 4) {
        // Обработка выбора времени
        const slots = action.slots || [];
        const slotIndex = parseInt(action.value) - 1;
        const selectedSlot = slots[slotIndex];

        if (!selectedSlot) {
          thinkingMsg.querySelector('.msg-text').textContent = 'Ошибка: выбранное время недоступно. Пожалуйста, выберите другое время.';
          return;
        }

        thinkingMsg.querySelector('.msg-text').innerHTML = `
          Вы выбрали: <strong>${selectedSlot}</strong><br>
          Подтвердите запись на это время?
        `;

        // Кнопки подтверждения
        const buttonsDiv = document.createElement('div');
        buttonsDiv.style.cssText = 'margin-top: 10px; display: flex; gap: 10px;';
        
        const yesBtn = document.createElement('button');
        yesBtn.textContent = 'Да';
        yesBtn.style.cssText = 'flex: 1; padding: 5px; background: #4CAF50; color: white; border: none; border-radius: 4px; cursor: pointer;';
        yesBtn.onclick = () => {
          addMessage('user', 'Подтверждаю запись');
          addMessage('assistant', 'Запись подтверждена! С вами свяжется менеджер для уточнения деталей.');
          isBooking = false;
          bookingData = {};
          showServiceTips();
        };

        const noBtn = document.createElement('button');
        noBtn.textContent = 'Нет';
        noBtn.style.cssText = 'flex: 1; padding: 5px; background: #f44336; color: white; border: none; border-radius: 4px; cursor: pointer;';
        noBtn.onclick = () => {
          addMessage('user', 'Отмена записи');
          addMessage('assistant', 'Запись отменена. Чем еще могу помочь?');
          isBooking = false;
          bookingData = {};
          showServiceTips();
        };

        buttonsDiv.appendChild(yesBtn);
        buttonsDiv.appendChild(noBtn);
        thinkingMsg.appendChild(buttonsDiv);
      } else if (step === 5) {
        // Формируем подтверждение записи
        const confirmationMsg = `
          <strong>Подтверждение записи:</strong><br>
          Имя: ${bookingData.name}<br>
          Телефон: ${bookingData.phone}<br>
          Услуга: ${bookingData.service}<br>
          Дата: ${bookingData.date}<br><br>
          Нажмите "Да" для подтверждения или "Нет" для отмены.
        `;
        
        thinkingMsg.querySelector('.msg-text').innerHTML = confirmationMsg;
        
        // Добавляем кнопки подтверждения
        const buttonsDiv = document.createElement('div');
        buttonsDiv.style.cssText = 'margin-top: 10px; display: flex; gap: 10px;';
        
        const yesBtn = document.createElement('button');
        yesBtn.textContent = 'Да';
        yesBtn.style.cssText = 'flex: 1; padding: 5px; background: #4CAF50; color: white; border: none; border-radius: 4px; cursor: pointer;';
        yesBtn.onclick = () => {
          addMessage('user', 'Подтверждаю запись');
          addMessage('assistant', 'Запись подтверждена! С вами свяжется менеджер для уточнения деталей.');
          isBooking = false;
          bookingData = {};
          showServiceTips();
        };
        
        const noBtn = document.createElement('button');
        noBtn.textContent = 'Нет';
        noBtn.style.cssText = 'flex: 1; padding: 5px; background: #f44336; color: white; border: none; border-radius: 4px; cursor: pointer;';
        noBtn.onclick = () => {
          addMessage('user', 'Отмена записи');
          addMessage('assistant', 'Запись отменена. Чем еще могу помочь?');
          isBooking = false;
          bookingData = {};
          showServiceTips();
        };
        
        buttonsDiv.appendChild(yesBtn);
        buttonsDiv.appendChild(noBtn);
        thinkingMsg.appendChild(buttonsDiv);
      }
      break;

    case 'show_price':
      const priceMsg = `
        <strong>Услуга:</strong> ${action.service.charAt(0).toUpperCase() + action.service.slice(1)}<br>
        <strong>Цена:</strong> ${action.price}<br>
        <strong>Описание:</strong> ${action.description}<br>
        <strong>Срок выполнения:</strong> ${action.duration}<br><br>
        Хотите записаться на эту услугу?
      `;
      thinkingMsg.querySelector('.msg-text').innerHTML = priceMsg;
      break;

    case 'contact_manager':
      thinkingMsg.querySelector('.msg-text').textContent = action.confirmation || 'Ваш запрос передан менеджеру. С вами свяжутся в ближайшее время.';
      break;

    default:
      thinkingMsg.querySelector('.msg-text').textContent = 'Произошла ошибка. Пожалуйста, попробуйте еще раз.';
  }
}

function showServiceTips() {
  const tips = [
    'Знаете, что мы работаем 24/7? Вы можете оставить заявку в любое время!',
    'Нужен срочный ремонт тента? Мы готовы помочь в течение 1 часа!',
    'Предоставляем услуги по подготовке TIR и CMR документов.',
    'Специальное предложение: при заказе ремонта тента - бесплатная диагностика!'
  ];
  
  const randomTip = tips[Math.floor(Math.random() * tips.length)];
  setTimeout(() => {
    addMessage('assistant', randomTip);
  }, 500);
}

function addMessage(role, text) {
  const messagesDiv = document.getElementById('chat-messages');
  const messageDiv = document.createElement('div');
  messageDiv.style.cssText = `
    margin: 8px 0;
    padding: 10px 15px;
    border-radius: 18px;
    max-width: 85%;
    word-wrap: break-word;
    animation: fadeIn 0.3s;
  `;

  if (role === 'user') {
    messageDiv.style.cssText += `
      background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
      color: white;
      margin-left: auto;
      align-self: flex-end;
    `;
  } else {
    messageDiv.style.cssText += `
      background: white;
      color: #333;
      border: 1px solid #e0e0e0;
      align-self: flex-start;
    `;
  }

  messageDiv.innerHTML = `<div class="msg-text">${text}</div>`;
  messagesDiv.appendChild(messageDiv);
  messagesDiv.scrollTop = messagesDiv.scrollHeight;
  
  return messageDiv;
}

// Добавляем CSS анимации в документ
function addCSS() {
  const style = document.createElement('style');
  style.textContent = `
    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(10px); }
      to { opacity: 1; transform: translateY(0); }
    }
    
    #chat-win {
      transition: all 0.3s ease;
    }
    
    #chat-messages {
      display: flex;
      flex-direction: column;
    }
    
    .msg-text {
      line-height: 1.4;
    }
    
    .msg-text strong {
      color: #1e3c72;
    }
    
    button {
      cursor: pointer;
    }
  `;
  document.head.appendChild(style);
}

// Инициализируем CSS
addCSS();

// Добавляем кнопку чата при загрузке
document.addEventListener('DOMContentLoaded', () => {
  setTimeout(createChatButton, 1000);
});
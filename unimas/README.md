# Uni+ (Unimas) - Asistente de Evaluación Pedagógica para Moodle

![Uni+ Logo](pix/logo.jpeg)

**Uni+** es un plugin local para Moodle diseñado para transformar el seguimiento estudiantil mediante el uso de Inteligencia Artificial. Proporciona a los docentes un tablero (dashboard) intuitivo que consolida indicadores de éxito, análisis cualitativos y recomendaciones pedagógicas personalizadas generadas por agentes de IA.

---

## 🚀 Requerimientos Técnicos

Para asegurar el correcto funcionamiento del plugin, se deben cumplir los siguientes requisitos:

*   **Moodle**: Versión 4.5 o superior.
*   **PHP**: Versión 8.1 o superior (recomendada la versión compatible con tu instancia de Moodle).
*   **Base de Datos**: PostgreSQL, MySQL, MariaDB o cualquier otra base de datos soportada oficialmente por Moodle.
*   **Conectividad**: El servidor debe tener salida a internet para comunicarse con los proveedores de IA (Google Gemini, OpenAI, etc.).
*   **Extensiones PHP**: `curl`, `json`, `mbstring`.

---

## ✨ Características Principales

### 1. Tablero de Seguimiento Estudiantil
Visualización clara del estado de todos los estudiantes en un curso, categorizados por niveles de prioridad:
*   🔴 **Prioritario**: Estudiantes con bajo desempeño o baja actividad.
*   🟡 **En Atención**: Estudiantes que muestran signos de alerta.
*   🟢 **Normal**: Estudiantes con buen ritmo y desempeño.
*   ⚪ **Sin Información**: Estudiantes sin registros de actividad recientes.

### 2. Indicador de Éxito (IS)
Un algoritmo propietario que calcula un puntaje de 0.0 a 1.0 basado en:
*   **Actividad LMS**: Interacciones con el contenido del curso.
*   **Calificaciones**: Rendimiento en evaluaciones.
*   **Entregas**: Puntualidad y cumplimiento de tareas.

### 3. Agente de IA Pedagógico
Integración con modelos de lenguaje de última generación para generar:
*   **Informes Individuales**: Análisis detallado del perfil del estudiante y sugerencias de intervención.
*   **Análisis Global**: Resumen del estado del curso y recomendaciones para el docente.
*   **Soporte Multilingüe**: El agente puede generar respuestas y la interfaz está disponible en Español, Inglés, Portugués y Gallego.

### 4. Carga de Contexto Cualitativo (Excel)
Permite a los docentes cargar formularios de encuestas estudiantiles en formato Excel (.xlsx) para enriquecer el análisis de la IA con la "voz del estudiante".

### 5. Registro de Acciones
Historial de intervenciones donde el docente puede registrar contactos realizados, citas agendadas o derivaciones a servicios de bienestar.

---

## 🛠️ Instalación

1.  Descarga o clona este repositorio.
2.  Copia la carpeta del plugin en el directorio `local/` de tu instalación de Moodle. La ruta final debe ser `moodle/local/unimas`.
3.  Accede a tu Moodle como administrador y ve a **Administración del sitio > Notificaciones**.
4.  Sigue los pasos para completar la instalación de las tablas de la base de datos.

---

## ⚙️ Configuración de IA

Para habilitar las funciones de IA, ve a la configuración del plugin dentro del dashboard (icono de engranaje):

1.  **Proveedor**: Selecciona entre Google Gemini (recomendado), OpenAI, Anthropic Claude o una URL personalizada.
2.  **API Key**: Ingresa la llave de API correspondiente.
3.  **Modelo**: Especifica el modelo a utilizar (ej: `gemini-1.5-pro` o `gpt-4o`).

---

## 📊 Estructura de Datos

El plugin crea las siguientes tablas en la base de datos:

*   `local_unimas_indicators`: Histórico semanal de los componentes del IS por estudiante.
*   `local_unimas_actions`: Registro de intervenciones docentes.
*   `local_unimas_context`: Almacena información cualitativa (encuestas) de los estudiantes.
*   `local_unimas_ai_cache`: Caché de las recomendaciones generadas para optimizar costos y velocidad.

---

## 👥 Créditos y Licencia

*   **Desarrollado por**: Sara Rojas (2026).
*   **Licencia**: GNU GPL v3 o posterior.
